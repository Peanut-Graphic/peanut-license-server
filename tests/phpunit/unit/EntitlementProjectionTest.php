<?php
use PHPUnit\Framework\TestCase;
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-entitlement-projection.php';
require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-admin-rest-api.php';
if (!function_exists('wp_timezone')) { function wp_timezone(): DateTimeZone { return new DateTimeZone('UTC'); } }

class ProjectionFixtureDatabase extends MockWPDB {
    public array $licenses = [];
    public array $queries = [];
    public function get_var(?string $query = null) {
        preg_match('/WHERE id = ([0-9]+)/', $query ?? '', $match);
        return isset($this->licenses[(int) ($match[1] ?? 0)]) ? $match[1] : null;
    }
    public function get_results(?string $query = null, $output = OBJECT): array {
        $this->queries[] = $query;
        preg_match('/IN \(([0-9,]+)\).*LIMIT ([0-9]+)/', $query ?? '', $match);
        if (!$match) throw new RuntimeException('Unexpected query');
        $ids = array_map('intval', explode(',', $match[1])); sort($ids);
        return array_slice(array_values(array_intersect_key($this->licenses, array_flip($ids))), 0, (int) $match[2]);
    }
    public function insert(string $table, array $data, $format = null): bool { throw new RuntimeException('Projection must not insert licenses'); }
    public function update(string $table, array $data, array $where, $format = null, $where_format = null) { throw new RuntimeException('Projection must not update licenses'); }
    public function delete(string $table, array $where, $format = null): int { throw new RuntimeException('Projection must not delete licenses'); }
    public function query(string $query) { throw new RuntimeException('Projection must not mutate licensing'); }
}

class EntitlementProjectionTest extends TestCase {
    private ProjectionFixtureDatabase $db;
    protected function setUp(): void {
        PeanutTestHelper::clearOptions(); PeanutTestHelper::clearTransients(); PeanutTestHelper::setUserCan(false);
        $this->db = new ProjectionFixtureDatabase(); $GLOBALS['wpdb'] = $this->db;
        foreach ([1, 2, 3] as $id) $this->db->licenses[$id] = (object) ['id' => $id, 'product_id' => 10, 'tier' => 'pro', 'status' => 'active', 'expires_at' => '2027-01-01 00:00:00', 'max_activations' => 3, 'activation_count' => 1, 'license_key' => 'NEVER-EXPORT-KEY', 'customer_email' => 'never@example.test', 'signing_secret' => 'NEVER-EXPORT-SIGNING'];
    }
    protected function tearDown(): void { $GLOBALS['wpdb'] = new MockWPDB(); PeanutTestHelper::clearOptions(); PeanutTestHelper::clearTransients(); PeanutTestHelper::setUserCan(true); }
    private function request(string $token, array $params = [], string $method = 'GET'): WP_REST_Request {
        $r = new WP_REST_Request($method, '/peanut-projection/v1/entitlements'); $r->set_header('Authorization', 'Bearer ' . $token);
        foreach ($params as $key => $value) $r->set_param($key, $value); return $r;
    }
    public function test_two_accounts_are_isolated_and_payload_and_storage_exclude_secrets(): void {
        $a = Peanut_Entitlement_Projection::provision('account-a', '12', [1, 2]); $b = Peanut_Entitlement_Projection::provision('account-b', '13', [3]);
        $response = Peanut_Entitlement_Projection::read($this->request($a)); $data = $response->get_data();
        $this->assertSame(['1', '2'], array_column($data['entitlements'], 'entitlement_id'));
        $this->assertSame('12', $data['hub_agency_id']); $this->assertSame('account-a', $data['source_account_id']);
        $this->assertSame(['3'], array_column(Peanut_Entitlement_Projection::read($this->request($b))->get_data()['entitlements'], 'entitlement_id'));
        $this->assertSame('2027-01-01T00:00:00Z', $data['entitlements'][0]['expires_at']);
        foreach (['NEVER-EXPORT-KEY', 'never@example.test', 'NEVER-EXPORT-SIGNING', $a, $b] as $secret) $this->assertStringNotContainsString($secret, json_encode($data));
        $this->assertStringNotContainsString($a, json_encode($GLOBALS['_mock_options']));
        $this->assertSame('private, no-store', $response->get_headers()['Cache-Control']);
        $this->assertStringNotContainsString('SELECT *', implode(' ', $this->db->queries));
        $this->assertStringNotContainsString('license_key', implode(' ', $this->db->queries));
    }
    public function test_pagination_is_deterministic_and_cannot_override_scope(): void {
        $token = Peanut_Entitlement_Projection::provision('account-a', '12', [1, 2]);
        $first = Peanut_Entitlement_Projection::read($this->request($token, ['per_page' => 1]))->get_data();
        $this->assertSame(['1'], array_column($first['entitlements'], 'entitlement_id')); $this->assertSame('1', $first['next_cursor']);
        $next = Peanut_Entitlement_Projection::read($this->request($token, ['per_page' => 1, 'cursor' => $first['next_cursor']]))->get_data();
        $this->assertSame(['2'], array_column($next['entitlements'], 'entitlement_id')); $this->assertNull($next['next_cursor']);
        foreach ([['agency' => 13], ['license_id' => 3], ['per_page' => 101], ['cursor' => '-1']] as $params) $this->assertInstanceOf(WP_Error::class, Peanut_Entitlement_Projection::read($this->request($token, $params)));
    }
    public function test_rotation_and_revocation_do_not_modify_licenses_and_tokens_do_not_authorize_mutation(): void {
        $before = serialize($this->db->licenses); $old = Peanut_Entitlement_Projection::provision('account-a', '12', [1]);
        $new = Peanut_Entitlement_Projection::provision('account-a', '12', [1], true);
        $this->assertInstanceOf(WP_Error::class, Peanut_Entitlement_Projection::permission($this->request($old)));
        $this->assertTrue(Peanut_Entitlement_Projection::permission($this->request($new)));
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) $this->assertInstanceOf(WP_Error::class, Peanut_Entitlement_Projection::read($this->request($new, [], $method)));
        $this->assertFalse(Peanut_Admin_REST_API::admin_permission_check($this->request($new)));
        Peanut_Entitlement_Projection::revoke('account-a'); $this->assertInstanceOf(WP_Error::class, Peanut_Entitlement_Projection::permission($this->request($new)));
        $this->assertSame($before, serialize($this->db->licenses));
    }
    public function test_binding_cannot_expand_on_rotation_and_missing_licenses_fail_closed(): void {
        Peanut_Entitlement_Projection::provision('account-a', '12', [1]);
        foreach ([['account-a', '13', [1], true], ['account-a', '12', [1, 3], true], ['account-a', '12', [1], false], ['missing', '12', [99], false]] as $args) {
            try { Peanut_Entitlement_Projection::provision(...$args); $this->fail('Invalid binding accepted'); } catch (InvalidArgumentException $e) { $this->assertNotEmpty($e->getMessage()); }
        }
    }
    public function test_database_outage_or_malformed_source_fails_without_partial_payload(): void {
        $token = Peanut_Entitlement_Projection::provision('account-a', '12', [1]);
        $this->db->last_error = 'sensitive database error'; $error = Peanut_Entitlement_Projection::read($this->request($token));
        $this->assertSame(503, $error->get_error_data()['status']); $this->assertStringNotContainsString('sensitive', $error->get_error_message());
        $this->db->last_error = null; $this->db->licenses[1]->status = 'unknown';
        $this->assertInstanceOf(WP_Error::class, Peanut_Entitlement_Projection::read($this->request($token)));
        $this->db->licenses[1]->status = 'active'; $this->db->licenses[1]->expires_at = '2027-02-30 00:00:00';
        $this->assertInstanceOf(WP_Error::class, Peanut_Entitlement_Projection::read($this->request($token)));
    }
    public function test_route_is_get_only_and_invalid_credentials_are_rejected(): void {
        Peanut_Entitlement_Projection::register_routes(); $route = $GLOBALS['_mock_rest_routes']['peanut-projection/v1/entitlements'];
        $this->assertSame('GET', $route['methods']); $this->assertSame([Peanut_Entitlement_Projection::class, 'permission'], $route['permission_callback']);
        $this->assertInstanceOf(WP_Error::class, Peanut_Entitlement_Projection::permission($this->request(str_repeat('f', 64))));
        $this->assertInstanceOf(WP_Error::class, Peanut_Entitlement_Projection::permission(new WP_REST_Request('GET')));
    }
    public function test_request_limit_is_scoped_to_principal(): void {
        $a = Peanut_Entitlement_Projection::provision('account-a', '12', [1]); $b = Peanut_Entitlement_Projection::provision('account-b', '13', [3]);
        for ($i = 0; $i < 60; $i++) $this->assertInstanceOf(WP_REST_Response::class, Peanut_Entitlement_Projection::read($this->request($a)));
        $this->assertSame(429, Peanut_Entitlement_Projection::read($this->request($a))->get_error_data()['status']);
        $this->assertInstanceOf(WP_REST_Response::class, Peanut_Entitlement_Projection::read($this->request($b)));
    }

}
