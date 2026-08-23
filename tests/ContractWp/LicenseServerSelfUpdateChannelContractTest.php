<?php
/**
 * Real-WordPress contract: the License Server serves its own canonical release.
 */

namespace Peanut\LicenseServer\Tests\ContractWp;

use WP_REST_Request;
use WP_UnitTestCase;

class LicenseServerSelfUpdateChannelContractTest extends WP_UnitTestCase
{
    public function test_updates_check_knows_license_server(): void
    {
        update_option('peanut_peanut-license-server_version', '1.4.6');

        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init');

        $request = new WP_REST_Request('GET', '/peanut-api/v1/updates/check');
        $request->set_param('plugin', 'peanut-license-server');
        $request->set_param('version', '1.4.5');
        $response = $wp_rest_server->dispatch($request);
        $data = $response->get_data();

        $this->assertArrayNotHasKey('error', (array) $data);
        $this->assertTrue((bool) ($data['update_available'] ?? false));
        $this->assertSame('1.4.6', $data['latest_version'] ?? null);
        $this->assertSame(
            'https://github.com/peanutgraphic/peanut-license-server/releases/download/v1.4.6/peanut-license-server-1.4.6.zip',
            $data['plugin_info']['download_url'] ?? null
        );
    }
}
