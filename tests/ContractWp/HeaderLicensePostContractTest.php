<?php
namespace Peanut\LicenseServer\Tests\ContractWp;

use WP_UnitTestCase;
use WP_REST_Request;

class HeaderLicensePostContractTest extends WP_UnitTestCase {
    private function request(string $route, ?string $header = null, ?string $body = null): WP_REST_Request {
        $request = new WP_REST_Request('POST', '/peanut-api/v1/license/' . $route);
        $request->set_param('site_url', 'https://synthetic.example.invalid');
        if ($header !== null) $request->set_header('X-Peanut-License-Key', $header);
        if ($body !== null) $request->set_param('license_key', $body);
        return $request;
    }

    private function license(): object {
        $license = \Peanut_License_Manager::create([
            'customer_email' => 'synthetic@example.invalid',
            'product_id' => 0,
            'tier' => 'pro',
            'max_activations' => 5,
        ]);
        $this->assertNotNull($license);
        return $license;
    }

    public static function routes(): array {
        return [['validate'], ['deactivate']];
    }

    /** @dataProvider routes */
    public function test_missing_key_remains_a_400_required_parameter_error(string $route): void {
        $response = rest_get_server()->dispatch($this->request($route));
        $this->assertSame(400, $response->get_status());
        $this->assertSame('rest_missing_callback_param', $response->get_data()['code']);
        $this->assertSame(['license_key'], $response->get_data()['data']['params']);
    }

    /** @dataProvider routes */
    public function test_invalid_header_cannot_fall_back_to_a_valid_body_key(string $route): void {
        $license = $this->license();
        $response = rest_get_server()->dispatch($this->request($route, 'invalid-key', $license->license_key));
        $this->assertSame(400, $response->get_status());
        $this->assertSame('rest_invalid_param', $response->get_data()['code']);
    }

    public function test_header_only_activation_and_deactivation_work_without_a_body_key(): void {
        $license = $this->license();
        foreach (['validate', 'deactivate'] as $route) {
            $response = rest_get_server()->dispatch($this->request($route, $license->license_key));
            $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
            $this->assertTrue($response->get_data()['success']);
        }
    }

    public function test_legacy_body_only_activation_and_deactivation_remain_supported(): void {
        $license = $this->license();
        foreach (['validate', 'deactivate'] as $route) {
            $response = rest_get_server()->dispatch($this->request($route, null, $license->license_key));
            $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
            $this->assertTrue($response->get_data()['success']);
        }
    }

    public function test_header_transport_does_not_bypass_an_existing_hardware_restriction(): void {
        $license = $this->license();
        $first = rest_get_server()->dispatch($this->request('validate', $license->license_key));
        $this->assertSame(200, $first->get_status());
        \Peanut_Security_Features::set_license_restrictions((int) $license->id, [
            'hardware_id' => 'Synthetic-Existing-Fingerprint',
            'enforce_hardware' => false,
        ]);
        $denied = rest_get_server()->dispatch($this->request('validate', $license->license_key));
        $this->assertSame(400, $denied->get_status());
        $this->assertFalse($denied->get_data()['success']);
        $this->assertSame('license_restricted', $denied->get_data()['error']);
    }
}
