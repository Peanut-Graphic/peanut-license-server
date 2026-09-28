<?php
/**
 * License key hash-lookup tests.
 *
 * The plaintext license_key column is on its way out: every lookup must go
 * through license_key_hash, keys must be normalized before hashing (MySQL's
 * case-insensitive collation used to hide lowercase input on the plaintext
 * path), the upgrade must backfill hashes for legacy rows, and GDPR
 * export/erase must reach validation logs through the hash (that table has
 * never had a license_key column).
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Records every query and lets a test script get_var / get_results answers.
 */
class KeyHashSpyWPDB extends MockWPDB {
    /** @var string[] */
    public array $queries = [];
    /** @var array<string,mixed> substring => get_var answer */
    public array $vars = [];
    /** @var array<string,array> substring => get_results answer */
    public array $results = [];

    public function get_var(?string $query = null) {
        $this->queries[] = (string) $query;
        foreach ($this->vars as $needle => $answer) {
            if ($query !== null && stripos($query, $needle) !== false) {
                return $answer;
            }
        }
        return null;
    }

    public function get_row(?string $query = null, $output = OBJECT, $offset = 0) {
        $this->queries[] = (string) $query;
        return null;
    }

    public function get_results(?string $query = null, $output = OBJECT): array {
        $this->queries[] = (string) $query;
        foreach ($this->results as $needle => $answer) {
            if ($query !== null && stripos($query, $needle) !== false) {
                return $answer;
            }
        }
        return [];
    }

    public function query(string $query) {
        $this->queries[] = $query;
        return true;
    }

    /** @return string[] */
    public function matching(string $needle): array {
        return array_values(array_filter($this->queries, fn($q) => stripos($q, $needle) !== false));
    }
}

/**
 * @covers Peanut_License_Manager
 * @covers Peanut_License_DB_Migrations
 * @covers Peanut_GDPR_Compliance
 */
class LicenseKeyHashLookupTest extends TestCase {

    private KeyHashSpyWPDB $spy;
    private $origWpdb;

    protected function setUp(): void {
        parent::setUp();
        PeanutTestHelper::clearOptions();
        global $wpdb;
        $this->origWpdb = $wpdb;
        $this->spy = new KeyHashSpyWPDB();
        $wpdb = $this->spy;
        $GLOBALS['wpdb'] = $this->spy;
        Peanut_License_DB_Migrations::$test_create_tables = static function (): void {};
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->origWpdb;
        $GLOBALS['wpdb'] = $this->origWpdb;
        Peanut_License_DB_Migrations::$test_create_tables = null;
        PeanutTestHelper::clearOptions();
        parent::tearDown();
    }

    /** @test */
    public function hash_normalizes_case_and_whitespace(): void {
        $this->assertSame(
            hash('sha256', 'ABCD-EF01-2345-6789'),
            Peanut_License_Manager::hash_license_key("  abcd-ef01-2345-6789\n")
        );
    }

    /** @test */
    public function get_by_key_looks_up_by_hash_only(): void {
        Peanut_License_Manager::get_by_key('abcd-ef01-2345-6789');

        $lookups = $this->spy->matching('peanut_licenses');
        $this->assertCount(1, $lookups);
        $this->assertStringContainsString(
            "license_key_hash = '" . hash('sha256', 'ABCD-EF01-2345-6789') . "'",
            $lookups[0]
        );
        $this->assertStringNotContainsString('abcd-ef01-2345-6789', $lookups[0], 'plaintext key must never reach SQL');
        $this->assertStringNotContainsString('ABCD-EF01-2345-6789', $lookups[0], 'plaintext key must never reach SQL');
        $this->assertDoesNotMatchRegularExpression('/\blicense_key\s*=/', $lookups[0]);
    }

    /** @test */
    public function validation_cache_key_is_case_insensitive_and_not_derived_from_md5_of_plaintext(): void {
        $upper = Peanut_License_Validator::get_cache_key('ABCD-EF01-2345-6789');
        $this->assertSame($upper, Peanut_License_Validator::get_cache_key(' abcd-ef01-2345-6789 '));
        $this->assertNotSame('peanut_lic_' . md5('ABCD-EF01-2345-6789'), $upper);
        $this->assertLessThanOrEqual(172, strlen($upper), 'transient names are capped at 172 chars');
    }

    /** @test */
    public function upgrade_backfills_missing_and_stale_hashes(): void {
        update_option('peanut_license_server_db_version', '1.6.0');

        Peanut_License_DB_Migrations::check_db_version();

        $backfill = $this->spy->matching('SET license_key_hash = SHA2(UPPER(TRIM(license_key)), 256)');
        $this->assertCount(1, $backfill, 'legacy rows must get a hash computed from the normalized key');
        $this->assertStringContainsString("license_key_hash = ''", $backfill[0]);
        $this->assertStringContainsString('license_key_hash IS NULL', $backfill[0]);
        $this->assertStringContainsString('license_key_hash <> SHA2(UPPER(TRIM(license_key)), 256)', $backfill[0]);
        $this->assertSame(Peanut_License_DB_Migrations::DB_VERSION, get_option('peanut_license_server_db_version'));
    }

    /** @test */
    public function upgrade_makes_the_hash_index_unique_when_no_duplicates(): void {
        update_option('peanut_license_server_db_version', '1.6.0');
        $this->spy->vars['INFORMATION_SCHEMA.STATISTICS'] = '1'; // existing non-unique idx_license_key_hash
        $this->spy->vars['HAVING COUNT(*) > 1'] = '0';

        Peanut_License_DB_Migrations::check_db_version();

        $alter = $this->spy->matching('ADD UNIQUE KEY unique_license_key_hash');
        $this->assertCount(1, $alter);
        $this->assertStringContainsString('DROP INDEX idx_license_key_hash', $alter[0]);
    }

    /** @test */
    public function upgrade_leaves_index_alone_when_duplicate_hashes_exist(): void {
        update_option('peanut_license_server_db_version', '1.6.0');
        $this->spy->vars['INFORMATION_SCHEMA.STATISTICS'] = '1';
        $this->spy->vars['HAVING COUNT(*) > 1'] = '2';

        Peanut_License_DB_Migrations::check_db_version();

        $this->assertSame([], $this->spy->matching('ADD UNIQUE KEY unique_license_key_hash'));
    }

    /** @test */
    public function upgrade_skips_unique_index_when_already_unique(): void {
        update_option('peanut_license_server_db_version', '1.6.0');
        $this->spy->vars['INFORMATION_SCHEMA.STATISTICS'] = '0';

        Peanut_License_DB_Migrations::check_db_version();

        $this->assertSame([], $this->spy->matching('ADD UNIQUE KEY'));
    }

    /** @test */
    public function gdpr_export_reaches_validation_logs_through_the_hash(): void {
        $this->spy->vars['SHOW TABLES'] = 'wp_peanut_validation_logs';
        $this->spy->results['FROM wp_peanut_licenses'] = [(object) ['id' => 7]];

        Peanut_GDPR_Compliance::export_customer_data('person@example.invalid');

        $q = $this->spy->matching('FROM wp_peanut_validation_logs');
        $this->assertCount(1, $q);
        $this->assertStringContainsString('license_key_hash IN (SELECT license_key_hash FROM wp_peanut_licenses', $q[0]);
        $this->assertDoesNotMatchRegularExpression('/SELECT license_key,/', $q[0], 'validation logs have no license_key column');
    }

    /** @test */
    public function gdpr_erase_deletes_validation_logs_by_hash(): void {
        $this->spy->vars['SHOW TABLES'] = 'present';
        $this->spy->results['FROM wp_peanut_licenses WHERE customer_email'] = [
            (object) ['id' => 7, 'license_key_hash' => str_repeat('a', 64)],
        ];

        Peanut_GDPR_Compliance::delete_customer_data('person@example.invalid');

        $q = $this->spy->matching('DELETE FROM wp_peanut_validation_logs');
        $this->assertCount(1, $q);
        $this->assertStringContainsString("license_key_hash IN ('" . str_repeat('a', 64) . "')", $q[0]);
    }
}
