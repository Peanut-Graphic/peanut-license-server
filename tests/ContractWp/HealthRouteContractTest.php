<?php
/**
 * Real-WordPress REST contract test (net 7) for the public health route.
 *
 * Pins the REAL `GET /peanut-api/v1/health` route registered by
 * \Peanut_API_Endpoints::register_routes() (wired on `rest_api_init` by the
 * plugin's main file). Its permission callback is
 * Peanut_API_Security::permission_public_readonly — public read-only by design
 * (no PII; an uptime/version probe client sites poll), so it is the stable,
 * gettable surface to lock down.
 *
 * Documented response shape (see Peanut_API_Endpoints::health_check):
 *   200 => [
 *     'status'         => 'ok',
 *     'version'        => <plugin version>,
 *     'plugin_version' => <managed plugin version>,
 *     'timestamp'      => <ISO-8601 string>,
 *   ]
 *
 * This boots a real WordPress and dispatches through the real REST server —
 * NO mocks. If the route or shape regresses, this fails.
 */

namespace Peanut\LicenseServer\Tests\ContractWp;

use WP_UnitTestCase;
use WP_REST_Request;

class HealthRouteContractTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();

        // The plugin self-boots on `init` and registers its REST routes on
        // `rest_api_init`. Rebuild the REST server so those routes are live for
        // this test.
        global $wp_rest_server;
        $wp_rest_server = null;
        do_action('rest_api_init');
    }

    public function test_health_route_is_registered(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(
            '/peanut-api/v1/health',
            $routes,
            'Public health route must be registered on a real WordPress.'
        );
    }

    public function test_get_health_returns_documented_contract(): void {
        $request  = new WP_REST_Request('GET', '/peanut-api/v1/health');
        $response = rest_get_server()->dispatch($request);

        // Real status from the real callback.
        $this->assertSame(
            200,
            $response->get_status(),
            'Public health endpoint must return HTTP 200.'
        );

        $data = $response->get_data();

        // Documented response-shape keys.
        $this->assertIsArray($data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('plugin_version', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertSame('ok', $data['status']);
    }
}
