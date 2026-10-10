<?php
/**
 * Per-license restriction enforcement tests.
 *
 * Admins can save an IP whitelist, allowed domains and a hardware fingerprint
 * per license (peanut-admin/v1/licenses/{id}/restrictions). Activation must
 * honour them and fail closed: a restricted license must never activate on a
 * domain / IP / machine it was not issued for, and a restriction that cannot
 * be read must not silently disappear.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Scripted $wpdb for the activation path.
 */
class RestrictionSpyWPDB extends MockWPDB {
    public ?object $license = null;
    public ?object $restrictions = null;
    public bool $restrictions_error = false;
    public bool $restrictions_table_missing = false;
    /** @var array<int,array> */
    public array $inserts = [];

    public function get_row(?string $query = null, $output = OBJECT, $offset = 0) {
        $query = (string) $query;
        $this->last_error = '';

        if (stripos($query, 'peanut_license_restrictions') !== false) {
            if ($this->restrictions_table_missing) {
                $this->last_error = "Table 'wp.wp_peanut_license_restrictions' doesn't exist";
                return null;
            }
            if ($this->restrictions_error) {
                $this->last_error = 'Lost connection to MySQL server during query';
                return null;
            }
            return $this->restrictions;
        }

        if (stripos($query, 'peanut_licenses') !== false) {
            return $this->license ? clone $this->license : null;
        }

        if (stripos($query, 'peanut_activations') !== false && stripos($query, 'WHERE id =') !== false) {
            return (object) ['id' => 501, 'license_id' => 7, 'is_active' => 1, 'activated_at' => '2026-10-01 00:00:00'];
        }

        return null;
    }

    public function get_var(?string $query = null) {
        $this->last_error = '';
        if ($query !== null && stripos($query, 'SHOW TABLES') !== false) {
            return $this->restrictions_table_missing ? null : 'wp_peanut_license_restrictions';
        }
        return 0;
    }

    public function insert(string $table, array $data, $format = null): bool {
        $this->last_error = '';
        $this->inserts[] = ['table' => $table, 'data' => $data];
        $this->insert_id = 501;
        return true;
    }
}

/**
 * @covers Peanut_License_Validator::validate_and_activate
 * @covers Peanut_Security_Features::validate_request
 */
class LicenseRestrictionEnforcementTest extends TestCase {

    private RestrictionSpyWPDB $spy;
    private $origWpdb;
    private $origRemoteAddr;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->origWpdb = $wpdb;
        $this->spy = new RestrictionSpyWPDB();
        $this->spy->license = (object) [
            'id'               => 7,
            'license_key'      => 'ABCD-EF01-2345-6789',
            'license_key_hash' => hash('sha256', 'ABCD-EF01-2345-6789'),
            'user_id'          => 3,
            'customer_email'   => 'buyer@example.com',
            'tier'             => 'pro',
            'status'           => 'active',
            'max_activations'  => 3,
            'expires_at'       => null,
        ];
        $wpdb = $this->spy;
        $GLOBALS['wpdb'] = $this->spy;
        $this->origRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->origWpdb;
        $GLOBALS['wpdb'] = $this->origWpdb;
        if ($this->origRemoteAddr === null) {
            unset($_SERVER['REMOTE_ADDR']);
        } else {
            $_SERVER['REMOTE_ADDR'] = $this->origRemoteAddr;
        }
        PeanutTestHelper::clearTransients();
        parent::tearDown();
    }

    private function restrict(array $fields): void {
        $this->spy->restrictions = (object) array_merge([
            'id'               => 1,
            'license_id'       => 7,
            'ip_whitelist'     => null,
            'allowed_domains'  => null,
            'hardware_id'      => null,
            'enforce_ip'       => 0,
            'enforce_domain'   => 0,
            'enforce_hardware' => 0,
        ], $fields);
    }

    private function activate(string $site_url, array $extra = []): array {
        $validator = new Peanut_License_Validator();
        return $validator->validate_and_activate('ABCD-EF01-2345-6789', array_merge([
            'site_url' => $site_url,
            'site_name' => 'Site',
            'plugin_version' => '1.0.0',
        ], $extra));
    }

    private function assertRefused(array $result): void {
        $this->assertFalse($result['success'], 'Restricted activation must be refused.');
        $this->assertSame('license_restricted', $result['error']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame([], $this->spy->inserts, 'No activation row may be written.');
    }

    /** @test */
    public function unrestricted_license_activates_as_before(): void {
        $result = $this->activate('https://anywhere.example.org');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->spy->inserts);
    }

    /** @test */
    public function domain_locked_license_refuses_another_domain(): void {
        $this->restrict(['allowed_domains' => wp_json_encode(['allowed.example.com']), 'enforce_domain' => 1]);

        $this->assertRefused($this->activate('https://other.example.net'));
    }

    /** @test */
    public function domain_locked_license_activates_on_its_domain(): void {
        $this->restrict(['allowed_domains' => wp_json_encode(['allowed.example.com']), 'enforce_domain' => 1]);

        $result = $this->activate('https://allowed.example.com');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->spy->inserts);
    }

    /** @test */
    public function domain_lock_applies_whenever_domains_are_saved(): void {
        // validate_request() has always treated a saved list as the lock; the
        // enforce_* columns are not consulted (see class docblock).
        $this->restrict(['allowed_domains' => wp_json_encode(['allowed.example.com'])]);

        $this->assertRefused($this->activate('https://other.example.net'));
    }

    /** @test */
    public function ip_whitelisted_license_refuses_another_ip(): void {
        $this->restrict(['ip_whitelist' => wp_json_encode(['198.51.100.0/24']), 'enforce_ip' => 1]);

        $this->assertRefused($this->activate('https://site.example.com'));
    }

    /** @test */
    public function ip_whitelisted_license_activates_from_an_allowed_ip(): void {
        $this->restrict(['ip_whitelist' => wp_json_encode(['203.0.113.0/24']), 'enforce_ip' => 1]);

        $this->assertTrue($this->activate('https://site.example.com')['success']);
    }

    /** @test */
    public function hardware_locked_license_refuses_a_request_without_a_fingerprint(): void {
        $this->restrict(['hardware_id' => str_repeat('a', 64), 'enforce_hardware' => 1]);

        $this->assertRefused($this->activate('https://site.example.com'));
    }

    /** @test */
    public function hardware_locked_license_refuses_a_different_fingerprint(): void {
        $this->restrict(['hardware_id' => str_repeat('a', 64), 'enforce_hardware' => 1]);

        $this->assertRefused($this->activate('https://site.example.com', ['hardware_id' => str_repeat('b', 64)]));
    }

    /** @test */
    public function hardware_locked_license_activates_with_the_matching_fingerprint(): void {
        $this->restrict(['hardware_id' => str_repeat('a', 64), 'enforce_hardware' => 1]);

        $this->assertTrue($this->activate('https://site.example.com', ['hardware_id' => str_repeat('A', 64)])['success']);
    }

    /** @test */
    public function unreadable_restriction_data_fails_closed(): void {
        $this->restrict(['allowed_domains' => '{not json']);

        $this->assertRefused($this->activate('https://allowed.example.com'));
    }

    /** @test */
    public function a_database_error_reading_restrictions_fails_closed(): void {
        $this->spy->restrictions_error = true;

        $this->assertRefused($this->activate('https://site.example.com'));
    }

    /** @test */
    public function the_validate_endpoint_forwards_the_hardware_fingerprint(): void {
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-validation-logger.php';
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-license-signer.php';
        $this->restrict(['hardware_id' => str_repeat('c', 64)]);

        $api = new Peanut_API_Endpoints();
        $request = PeanutTestHelper::createMockRequest('POST', '/peanut-api/v1/license/validate', [
            'license_key' => 'ABCD-EF01-2345-6789',
            'site_url'    => 'https://site.example.com',
            'hardware_id' => str_repeat('c', 64),
        ]);

        $response = $api->validate_license($request);

        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
        $this->assertTrue($response->get_data()['success']);

        $request->set_param('hardware_id', '');
        $this->spy->inserts = [];
        $refused = $api->validate_license($request);
        $this->assertSame(400, $refused->get_status());
        $this->assertSame('license_restricted', $refused->get_data()['error']);
    }

    /** @test */
    public function a_missing_restrictions_table_means_no_restrictions(): void {
        // Auto-updates do not run the activation hook and the schema
        // self-heal does not track this table, so an install can lack it.
        // No restriction can have been saved then; refusing every activation
        // would be an outage, not a security control.
        $this->spy->restrictions_table_missing = true;

        $result = $this->activate('https://site.example.com');

        $this->assertTrue($result['success']);
        $this->assertCount(1, $this->spy->inserts);
    }

    /** @test */
    public function validate_request_reports_each_failed_check(): void {
        $this->restrict([
            'allowed_domains' => wp_json_encode(['allowed.example.com']),
            'ip_whitelist'    => wp_json_encode(['198.51.100.7']),
        ]);

        $result = Peanut_Security_Features::validate_request(7, ['site_url' => 'https://other.example.net']);

        $this->assertFalse($result['valid']);
        $this->assertFalse($result['checks']['ip']);
        $this->assertFalse($result['checks']['domain']);
        $this->assertCount(2, $result['errors']);
    }
}
