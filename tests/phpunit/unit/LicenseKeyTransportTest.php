<?php
/**
 * License-key transport tests.
 *
 * A license key in a URL query string ends up in web-server and proxy access
 * logs, browser history and Referer headers. Clients can now send the key in
 * an `X-Peanut-License-Key` header (every route that takes a key) or in a POST
 * body (/license/status). The query-string forms keep working for the
 * installed base — they are deprecated, not removed — and are flagged with a
 * `Deprecation` response header.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Answers every licenses-table lookup with one active license.
 */
class TransportSpyWPDB extends MockWPDB {
    public ?object $license = null;

    public function get_row(?string $query = null, $output = OBJECT, $offset = 0) {
        if ($this->license && $query !== null && stripos($query, 'peanut_licenses') !== false) {
            return clone $this->license;
        }
        return null;
    }
}

/**
 * @covers Peanut_API_Endpoints
 * @covers Peanut_API_Security::permission_public_license
 */
class LicenseKeyTransportTest extends TestCase {

    private const KEY = 'ABCD-EF01-2345-6789';

    private TransportSpyWPDB $spy;
    private $origWpdb;
    private Peanut_API_Endpoints $api;

    protected function setUp(): void {
        parent::setUp();
        global $wpdb;
        $this->origWpdb = $wpdb;
        $this->spy = new TransportSpyWPDB();
        $wpdb = $this->spy;
        $GLOBALS['wpdb'] = $this->spy;
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
        $this->api = new Peanut_API_Endpoints();
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->origWpdb;
        $GLOBALS['wpdb'] = $this->origWpdb;
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
        parent::tearDown();
    }

    private function activeLicense(): object {
        return (object) [
            'id'               => 11,
            'license_key'      => self::KEY,
            'license_key_hash' => hash('sha256', self::KEY),
            'user_id'          => 3,
            'customer_email'   => 'buyer@example.com',
            'tier'             => 'pro',
            'status'           => 'active',
            'max_activations'  => 3,
            'expires_at'       => null,
        ];
    }

    private function headerName(WP_REST_Response $response, string $name): ?string {
        foreach ($response->get_headers() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return (string) $value;
            }
        }
        return null;
    }

    // ---------------------------------------------------------------------
    // /license/status
    // ---------------------------------------------------------------------

    /** @test */
    public function status_route_accepts_get_and_post_and_does_not_require_the_query_param(): void {
        global $_mock_rest_routes;
        $this->api->register_routes();

        $route = $_mock_rest_routes['peanut-api/v1/license/status'];
        $methods = (array) $route['methods'];

        $this->assertContains('GET', $methods);
        $this->assertContains('POST', $methods);
        $this->assertFalse($route['args']['license_key']['required'], 'A header-only request must reach the handler.');
    }

    /** @test */
    public function status_reads_the_key_from_the_header(): void {
        $this->spy->license = $this->activeLicense();
        $request = new WP_REST_Request('GET', '/peanut-api/v1/license/status');
        $request->set_header('X-Peanut-License-Key', strtolower(self::KEY));

        $response = $this->api->get_license_status($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
        $this->assertNull($this->headerName($response, 'Deprecation'));
    }

    /** @test */
    public function status_reads_the_key_from_a_post_body(): void {
        $this->spy->license = $this->activeLicense();
        $request = PeanutTestHelper::createMockRequest('POST', '/peanut-api/v1/license/status', [
            'license_key' => self::KEY,
        ]);

        $response = $this->api->get_license_status($request);

        $this->assertSame(200, $response->get_status());
        $this->assertNull($this->headerName($response, 'Deprecation'));
    }

    /** @test */
    public function status_still_accepts_the_query_string_and_flags_it_deprecated(): void {
        $this->spy->license = $this->activeLicense();
        $request = new WP_REST_Request('GET', '/peanut-api/v1/license/status');
        $request->set_query_params(['license_key' => self::KEY]);

        $response = $this->api->get_license_status($request);

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($response->get_data()['success']);
        $this->assertSame('true', $this->headerName($response, 'Deprecation'));
    }

    /** @test */
    public function status_without_any_key_is_a_400_missing_parameter(): void {
        $request = new WP_REST_Request('GET', '/peanut-api/v1/license/status');

        $response = $this->api->get_license_status($request);

        $this->assertInstanceOf(WP_Error::class, $response);
        $this->assertSame('rest_missing_callback_param', $response->get_error_code());
        $this->assertSame(400, $response->get_error_data()['status']);
    }

    /** @test */
    public function permission_check_validates_a_header_supplied_key(): void {
        $request = new WP_REST_Request('GET', '/peanut-api/v1/license/status');
        $request->set_header('X-Peanut-License-Key', 'not-a-key');

        $result = Peanut_API_Security::permission_public_license($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rest_invalid_param', $result->get_error_code());
    }

    // ---------------------------------------------------------------------
    // Update checks
    // ---------------------------------------------------------------------

    private function advertiseUpdate(): void {
        PeanutTestHelper::setOption('peanut_peanut-suite_version', '9.9.9');
    }

    /** @test */
    public function update_check_reads_the_key_from_the_header(): void {
        $this->advertiseUpdate();
        $this->spy->license = $this->activeLicense();
        $request = PeanutTestHelper::createMockRequest('GET', '/peanut-api/v1/updates/check', [
            'plugin' => 'peanut-suite',
            'version' => '1.0.0',
        ]);
        $request->set_header('X-Peanut-License-Key', self::KEY);

        $response = $this->api->check_update($request);

        $this->assertTrue($response->get_data()['update_available']);
        $this->assertTrue($response->get_data()['can_download'], 'The header key was not used.');
        $this->assertNull($this->headerName($response, 'Deprecation'));
    }

    /** @test */
    public function update_check_still_accepts_the_query_string_and_flags_it_deprecated(): void {
        $this->advertiseUpdate();
        $this->spy->license = $this->activeLicense();
        $request = new WP_REST_Request('GET', '/peanut-api/v1/updates/check');
        $request->set_query_params([
            'plugin' => 'peanut-suite',
            'version' => '1.0.0',
            'license' => self::KEY,
        ]);

        $response = $this->api->check_update($request);

        $this->assertTrue($response->get_data()['can_download']);
        $this->assertSame('true', $this->headerName($response, 'Deprecation'));
    }

    /** @test */
    public function per_product_update_route_reads_the_key_from_the_header(): void {
        $this->advertiseUpdate();
        $this->spy->license = $this->activeLicense();
        $request = PeanutTestHelper::createMockRequest('GET', '/peanut-api/v1/updates/peanut-suite/1.0.0', [
            'plugin' => 'peanut-suite',
            'current_version' => '1.0.0',
        ]);
        $request->set_header('X-Peanut-License-Key', self::KEY);

        $response = $this->api->check_product_update($request);

        $this->assertTrue($response->get_data()['can_download']);
    }

    /** @test */
    public function download_route_authorizes_a_header_supplied_key(): void {
        $this->spy->license = $this->activeLicense();
        // formflow: a product with no ZIP staged in releases/, so an
        // authorized request stops at "file not found" instead of streaming
        // (peanut-suite has a real releases/peanut-suite.zip, and serving it
        // would exit the test runner).
        $request = PeanutTestHelper::createMockRequest('GET', '/peanut-api/v1/updates/download', [
            'plugin' => 'formflow',
        ]);
        $request->set_header('X-Peanut-License-Key', self::KEY);

        try {
            $this->api->download_plugin($request);
            $this->fail('download_plugin() should stream or die.');
        } catch (Exception $e) {
            $this->assertSame('Download file not found.', $e->getMessage());
        }
    }
}
