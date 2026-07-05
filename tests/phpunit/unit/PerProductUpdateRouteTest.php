<?php
/**
 * Per-Product Update Route Tests
 *
 * Guards the route that absorbs the per-product update mu-plugins:
 *   /updates/<plugin>/<current_version>   (e.g. /updates/peanut-connect/3.20.0)
 *
 * The peanut-connect client updater (and the other per-product updaters) call
 * this path-segment shape rather than the ?plugin=&version= query form served
 * by /updates/check. This test proves the handler resolves for a known product,
 * hands back the SAME already-unified canonical GitHub Releases package that
 * get_download_url() produces, and rejects unknown slugs — so the mu-plugins can
 * be retired.
 *
 * Runs on the self-contained mock-WordPress bootstrap (no DB, no real WP).
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers Peanut_API_Endpoints::check_product_update
 */
class PerProductUpdateRouteTest extends TestCase {

    private Peanut_API_Endpoints $api;

    protected function setUp(): void {
        parent::setUp();
        $this->api = new Peanut_API_Endpoints();
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
    }

    protected function tearDown(): void {
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
        parent::tearDown();
    }

    /**
     * The route the mu-plugins answer (/updates/<slug>/<version>) resolves for a
     * known product and returns the canonical GitHub-release download URL for the
     * advertised version — identical to what get_download_url() produces.
     *
     * @test
     */
    public function known_product_route_returns_github_release_download_url(): void {
        PeanutTestHelper::setOption('peanut_peanut-connect_version', '3.20.0');

        // {plugin} and {current_version} arrive as URL path params.
        $request = PeanutTestHelper::createMockRequest(
            'GET',
            '/peanut-api/v1/updates/peanut-connect/3.19.0',
            [
                'plugin' => 'peanut-connect',
                'current_version' => '3.19.0',
            ]
        );

        $response = $this->api->check_product_update($request);

        $this->assertInstanceOf(WP_REST_Response::class, $response);
        $this->assertSame(200, $response->get_status());

        $data = $response->get_data();
        $this->assertTrue($data['update_available']);
        $this->assertSame('3.19.0', $data['current_version']);
        $this->assertSame('3.20.0', $data['latest_version']);
        $this->assertArrayHasKey('plugin_info', $data);

        $expected = 'https://github.com/peanutgraphic/peanut-connect/releases/download/v3.20.0/peanut-connect-3.20.0.zip';
        $this->assertSame($expected, $data['plugin_info']['download_url']);
        $this->assertSame($expected, $data['plugin_info']['package']);

        // Cross-check: the route payload matches get_download_url() exactly.
        $server = new Peanut_Update_Server('peanut-connect');
        $this->assertSame($server->get_download_url(), $data['plugin_info']['download_url']);
    }

    /**
     * A bare version (missing path segment) still resolves, defaulting to 0.0.0
     * so any advertised version reads as an available update.
     *
     * @test
     */
    public function missing_current_version_defaults_to_zero(): void {
        PeanutTestHelper::setOption('peanut_peanut-suite_version', '4.2.2');

        $request = PeanutTestHelper::createMockRequest(
            'GET',
            '/peanut-api/v1/updates/peanut-suite',
            ['plugin' => 'peanut-suite']
        );

        $response = $this->api->check_product_update($request);
        $data = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['update_available']);
        $this->assertSame('0.0.0', $data['current_version']);
        $this->assertSame('4.2.2', $data['latest_version']);
    }

    /**
     * Unknown product slugs are rejected 400 (mirrors /updates/check), so the
     * generic route never serves an unregistered plugin.
     *
     * @test
     */
    public function unknown_product_route_returns_400(): void {
        $request = PeanutTestHelper::createMockRequest(
            'GET',
            '/peanut-api/v1/updates/not-a-real-plugin/1.0.0',
            ['plugin' => 'not-a-real-plugin', 'current_version' => '1.0.0']
        );

        $response = $this->api->check_product_update($request);

        $this->assertSame(400, $response->get_status());
        $data = $response->get_data();
        $this->assertSame('invalid_plugin', $data['error']);
        $this->assertArrayHasKey('valid_plugins', $data);
        $this->assertContains('peanut-connect', $data['valid_plugins']);
    }

    /**
     * The route shares the update_check rate-limit bucket and returns its
     * headers, same as /updates/check.
     *
     * @test
     */
    public function route_includes_rate_limit_headers(): void {
        PeanutTestHelper::setOption('peanut_formflow_version', '4.0.7');

        $request = PeanutTestHelper::createMockRequest(
            'GET',
            '/peanut-api/v1/updates/formflow/4.0.0',
            ['plugin' => 'formflow', 'current_version' => '4.0.0']
        );

        $response = $this->api->check_product_update($request);
        $headers = $response->get_headers();

        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);
    }

    /**
     * Registering the routes (including the new generic per-product route) does
     * not throw — the regex with its reserved-word negative lookahead is valid.
     *
     * @test
     */
    public function register_routes_with_generic_route_does_not_throw(): void {
        $this->expectNotToPerformAssertions();
        $this->api->register_routes();
    }
}
