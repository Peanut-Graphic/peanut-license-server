<?php
/**
 * License key vault tests: encryption at rest, keyed lookup hashes, the
 * migration sweep, and the guards that keep a missing/wrong secret or an
 * unwidened column from destroying keys.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Scriptable $wpdb for sweep tests: serves a fixed row set, records writes.
 */
class VaultSpyWPDB extends KeyHashSpyWPDB {
    /** @var array<int,array{table:string,data:array,where:array}> */
    public array $updates = [];

    public function update(string $table, array $data, array $where, $format = null, $where_format = null) {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        return 1;
    }
}

/**
 * @covers Peanut_License_Key_Vault
 */
class LicenseKeyVaultTest extends TestCase {

    private const KEY = 'ABCD-EF01-2345-6789';

    private VaultSpyWPDB $spy;
    private $origWpdb;

    protected function setUp(): void {
        parent::setUp();
        PeanutTestHelper::clearOptions();
        global $wpdb;
        $this->origWpdb = $wpdb;
        $this->spy = new VaultSpyWPDB();
        $wpdb = $this->spy;
        $GLOBALS['wpdb'] = $this->spy;
        $this->enable(base64_encode(str_repeat("\x01", 32)));
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->origWpdb;
        $GLOBALS['wpdb'] = $this->origWpdb;
        Peanut_License_Key_Vault::$test_secret = null;
        Peanut_License_Key_Vault::reset();
        PeanutTestHelper::clearOptions();
        parent::tearDown();
    }

    private function enable($secret, bool $column_ready = true): void {
        Peanut_License_Key_Vault::$test_secret = $secret;
        if ($column_ready) {
            update_option(Peanut_License_Key_Vault::COLUMN_READY_OPTION, '1');
        } else {
            delete_option(Peanut_License_Key_Vault::COLUMN_READY_OPTION);
        }
        Peanut_License_Key_Vault::reset();
    }

    /** @test */
    public function seal_and_reveal_round_trip_without_plaintext_in_storage(): void {
        $stored = Peanut_License_Key_Vault::seal(self::KEY);

        $this->assertStringStartsWith('plk1:', $stored);
        $this->assertStringNotContainsString(self::KEY, $stored);
        $this->assertLessThanOrEqual(128, strlen($stored), 'must fit the widened column');
        $this->assertNotSame($stored, Peanut_License_Key_Vault::seal(self::KEY), 'nonce must be random');
        $this->assertSame(self::KEY, Peanut_License_Key_Vault::reveal($stored));
    }

    /** @test */
    public function tampered_ciphertext_does_not_reveal(): void {
        $stored = Peanut_License_Key_Vault::seal(self::KEY);
        $raw = base64_decode(substr($stored, 5));
        $raw[30] = $raw[30] ^ "\x01";

        $this->assertNull(Peanut_License_Key_Vault::reveal('plk1:' . base64_encode($raw)));
    }

    /** @test */
    public function wrong_secret_cannot_reveal_and_rows_show_empty_not_ciphertext(): void {
        $stored = Peanut_License_Key_Vault::seal(self::KEY);
        $this->enable(base64_encode(str_repeat("\x02", 32)));

        $this->assertNull(Peanut_License_Key_Vault::reveal($stored));
        $row = Peanut_License_Key_Vault::reveal_rows((object) ['license_key' => $stored]);
        $this->assertSame('', $row->license_key);
    }

    /** @test */
    public function plaintext_rows_pass_through_during_migration(): void {
        $rows = Peanut_License_Key_Vault::reveal_rows([
            (object) ['license_key' => self::KEY],
            (object) ['license_key' => Peanut_License_Key_Vault::seal('1111-2222-3333-4444')],
        ]);
        $this->assertSame(self::KEY, $rows[0]->license_key);
        $this->assertSame('1111-2222-3333-4444', $rows[1]->license_key);
    }

    /** @test */
    public function lookup_hash_is_keyed_normalized_and_differs_from_legacy(): void {
        $hmac = Peanut_License_Key_Vault::lookup_hash(self::KEY);

        $this->assertSame($hmac, Peanut_License_Key_Vault::lookup_hash(' abcd-ef01-2345-6789 '));
        $this->assertNotSame(hash('sha256', self::KEY), $hmac);
        $this->assertSame([$hmac, hash('sha256', self::KEY)], Peanut_License_Key_Vault::candidate_hashes(self::KEY));
    }

    /** @test */
    public function get_by_key_matches_keyed_and_legacy_hash_never_plaintext(): void {
        Peanut_License_Manager::get_by_key(strtolower(self::KEY));

        $q = $this->spy->matching('FROM wp_peanut_licenses')[0];
        $this->assertStringContainsString(Peanut_License_Key_Vault::lookup_hash(self::KEY), $q);
        $this->assertStringContainsString(hash('sha256', self::KEY), $q);
        $this->assertStringNotContainsStringIgnoringCase(self::KEY, $q);
    }

    /** @test */
    public function disabled_without_secret_keeps_phase_one_behavior(): void {
        $this->enable(false);

        $this->assertFalse(Peanut_License_Key_Vault::enabled());
        $this->assertSame(self::KEY, Peanut_License_Key_Vault::seal(self::KEY));
        $this->assertSame(hash('sha256', self::KEY), Peanut_License_Key_Vault::lookup_hash(self::KEY));
        $this->assertSame('disabled', Peanut_License_Key_Vault::secret_status());
    }

    /** @test */
    public function short_or_non_base64_secret_disables_rather_than_weakens(): void {
        $this->enable(base64_encode(str_repeat("\x01", 16)));
        $this->assertFalse(Peanut_License_Key_Vault::enabled());

        $this->enable('not base64 !!');
        $this->assertFalse(Peanut_License_Key_Vault::enabled());
    }

    /** @test */
    public function stays_off_until_the_column_can_hold_ciphertext(): void {
        $this->enable(base64_encode(str_repeat("\x01", 32)), false);

        $this->assertFalse(Peanut_License_Key_Vault::enabled());
        $this->assertSame(self::KEY, Peanut_License_Key_Vault::seal(self::KEY), 'must not truncate into VARCHAR(64)');
    }

    /** @test */
    public function sweep_encrypts_rehashes_and_moves_validation_logs(): void {
        $this->spy->results['NOT LIKE'] = [(object) ['id' => 3, 'license_key' => self::KEY]];

        $this->assertSame(1, Peanut_License_Key_Vault::sweep());

        $u = $this->spy->updates[0];
        $this->assertSame('wp_peanut_licenses', $u['table']);
        $this->assertSame(self::KEY, Peanut_License_Key_Vault::reveal($u['data']['license_key']));
        $this->assertSame(Peanut_License_Key_Vault::lookup_hash(self::KEY), $u['data']['license_key_hash']);
        $this->assertSame(['id' => 3, 'license_key' => self::KEY], $u['where'], 'compare-and-swap on the plaintext');

        $logs = $this->spy->matching('UPDATE wp_peanut_validation_logs');
        $this->assertCount(1, $logs);
        $this->assertStringContainsString(hash('sha256', self::KEY), $logs[0]);
    }

    /** @test */
    public function sweep_refuses_when_secret_does_not_match_canary(): void {
        $this->assertSame('ok', Peanut_License_Key_Vault::secret_status()); // records canary
        $this->enable(base64_encode(str_repeat("\x03", 32)));
        $this->spy->results['NOT LIKE'] = [(object) ['id' => 3, 'license_key' => self::KEY]];

        $this->assertSame('mismatch', Peanut_License_Key_Vault::secret_status());
        $this->assertSame(0, Peanut_License_Key_Vault::sweep());
        $this->assertSame([], $this->spy->updates);
    }

    /** @test */
    public function create_stores_ciphertext_and_keyed_hash(): void {
        $captured = null;
        $this->spy = new class extends VaultSpyWPDB {
            public ?array $inserted = null;
            public function insert(string $table, array $data, $format = null): bool {
                $this->inserted = $data;
                $this->insert_id = 9;
                return true;
            }
        };
        $GLOBALS['wpdb'] = $this->spy;

        Peanut_License_Manager::create(['customer_email' => 'a@example.invalid', 'product_id' => 1]);

        $row = $this->spy->inserted;
        $this->assertTrue(Peanut_License_Key_Vault::is_encrypted($row['license_key']));
        $plain = Peanut_License_Key_Vault::reveal($row['license_key']);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{4}(-[A-F0-9]{4}){3}$/', $plain);
        $this->assertSame(Peanut_License_Key_Vault::lookup_hash($plain), $row['license_key_hash']);
    }

    /** @test */
    public function admin_search_finds_full_key_by_hash_and_never_likes_the_key_column(): void {
        Peanut_License_Manager::get_all(['search' => strtolower(self::KEY)]);
        Peanut_License_Manager::get_all(['search' => 'ABCD']);

        foreach ($this->spy->matching('FROM wp_peanut_licenses WHERE') as $q) {
            $this->assertDoesNotMatchRegularExpression('/license_key LIKE/', $q);
        }
        $full = $this->spy->matching('SELECT * FROM wp_peanut_licenses WHERE')[0];
        $this->assertStringContainsString(Peanut_License_Key_Vault::lookup_hash(self::KEY), $full);
    }
}
