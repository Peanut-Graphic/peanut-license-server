<?php
namespace Peanut\LicenseServer\Tests\ContractWp;
use WP_REST_Request;
use WP_UnitTestCase;

/** Real REST dispatch and database isolation; never falls back to the mock suite. */
class EntitlementProjectionContractTest extends WP_UnitTestCase {
    private array $ids = [];
    private string $accountA;
    private string $accountB;
    public function set_up(): void {
        parent::set_up();
        \Peanut_License_Server::get_instance()->ensure_tables();
        $suffix = bin2hex(random_bytes(5)); $this->accountA = 'contract-a-' . $suffix; $this->accountB = 'contract-b-' . $suffix;
        global $wpdb;
        foreach ([1, 2] as $n) {
            $key = 'contract-' . $suffix . '-' . $n;
            $wpdb->insert(\Peanut_License_Manager::get_table_name(), ['license_key' => $key, 'license_key_hash' => hash('sha256', $key), 'customer_email' => 'fixture-' . $n . '@example.test', 'product_id' => 10, 'tier' => 'pro', 'status' => 'active', 'max_activations' => 3]);
            $this->ids[] = (int) $wpdb->insert_id;
        }
        wp_set_current_user(0);
        global $wp_rest_server; $wp_rest_server = new \WP_REST_Server(); do_action('rest_api_init');
    }
    public function tear_down(): void {
        foreach ([$this->accountA, $this->accountB] as $account) {
            \Peanut_Entitlement_Projection::revoke($account);
            delete_option('peanut_projection_account_' . hash('sha256', $account));
        }
        global $wpdb;
        foreach ($this->ids as $id) $wpdb->delete(\Peanut_License_Manager::get_table_name(), ['id' => $id], ['%d']);
        parent::tear_down();
    }
    private function dispatch(string $token, string $method = 'GET', string $route = '/peanut-projection/v1/entitlements') {
        $request = new WP_REST_Request($method, $route); $request->set_header('Authorization', 'Bearer ' . $token);
        // Supply a valid create payload so WordPress reaches the permission check.
        if ($method === 'POST' && $route === '/peanut-admin/v1/licenses') { $request->set_param('customer_email', 'denied@example.test'); $request->set_param('product_id', 10); }
        return rest_get_server()->dispatch($request);
    }
    public function test_read_principals_are_isolated_rotatable_revocable_and_cannot_write(): void {
        $a = \Peanut_Entitlement_Projection::provision($this->accountA, '12', [$this->ids[0]]);
        $b = \Peanut_Entitlement_Projection::provision($this->accountB, '13', [$this->ids[1]]);
        $this->assertSame(200, $this->dispatch($a)->get_status());
        $this->assertSame([(string) $this->ids[0]], array_column($this->dispatch($a)->get_data()['entitlements'], 'entitlement_id'));
        $this->assertSame([(string) $this->ids[1]], array_column($this->dispatch($b)->get_data()['entitlements'], 'entitlement_id'));
        $data = wp_json_encode($this->dispatch($a)->get_data());
        $this->assertStringNotContainsString('fixture-', $data); $this->assertStringNotContainsString('license_key', $data);
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) $this->assertNotSame(200, $this->dispatch($a, $method)->get_status());
        foreach ([['POST', '/peanut-admin/v1/licenses'], ['PUT', '/peanut-admin/v1/licenses/' . $this->ids[0]], ['DELETE', '/peanut-admin/v1/licenses/' . $this->ids[0]], ['POST', '/peanut-admin/v1/licenses/' . $this->ids[0] . '/suspend'], ['POST', '/peanut-admin/v1/licenses/' . $this->ids[0] . '/regenerate']] as [$method, $route]) $this->assertContains($this->dispatch($a, $method, $route)->get_status(), [401, 403]);
        $new = \Peanut_Entitlement_Projection::provision($this->accountA, '12', [$this->ids[0]], true);
        $this->assertSame(401, $this->dispatch($a)->get_status()); $this->assertSame(200, $this->dispatch($new)->get_status());
        \Peanut_Entitlement_Projection::revoke($this->accountA); $this->assertSame(401, $this->dispatch($new)->get_status());
        $this->assertSame(200, $this->dispatch($b)->get_status());
        global $wpdb;
        $this->assertSame('active', $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . \Peanut_License_Manager::get_table_name() . ' WHERE id = %d', $this->ids[0])));
    }
}
