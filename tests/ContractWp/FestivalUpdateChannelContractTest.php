<?php
/**
 * Real-WordPress contract test: the unified /updates/check endpoint must know
 * peanut-festival.
 *
 * Pins the gap found while shipping festival 1.3.2 (its first signed
 * release): the publisher set peanut_peanut-festival_version and the canary
 * installed cleanly, but /updates/check answered
 *   {"error":"invalid_plugin","valid_plugins":[...five slugs...]}
 * because festival was never in Peanut_Update_Server::PRODUCTS -- correctly,
 * at the time, since pre-1.3.2 festival had no updater and no signature gate,
 * so auto-offered packages would have installed unverified. 1.3.2 bundles the
 * formflow-core SignedUpdateGate, which is the precondition this test now
 * assumes.
 */

namespace Peanut\LicenseServer\Tests\ContractWp;

use WP_REST_Request;
use WP_UnitTestCase;

class FestivalUpdateChannelContractTest extends WP_UnitTestCase
{
    public function test_updates_check_knows_festival(): void
    {
        update_option('peanut_peanut-festival_version', '1.3.2');

        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init');

        $request = new WP_REST_Request('GET', '/peanut-api/v1/updates/check');
        $request->set_param('plugin', 'peanut-festival');
        $request->set_param('version', '0.0.1');
        $response = $wp_rest_server->dispatch($request);
        $data = $response->get_data();

        $this->assertArrayNotHasKey(
            'error',
            (array) $data,
            'updates/check must not answer invalid_plugin for peanut-festival -- that is the exact response the 1.3.2 release hit'
        );
        $this->assertTrue((bool) ($data['update_available'] ?? false));
        $this->assertSame('1.3.2', $data['latest_version'] ?? null);
    }
}
