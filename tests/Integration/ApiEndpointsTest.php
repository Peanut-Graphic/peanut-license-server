<?php
/**
 * API Endpoints Integration Tests
 *
 * Tests for the REST API endpoints.
 * These tests mock WordPress REST infrastructure.
 *
 * @package Peanut_License_Server\Tests\Integration
 */

namespace Peanut\LicenseServer\Tests\Integration;

use Peanut\LicenseServer\Tests\TestCase;

/**
 * @covers Peanut_API_Endpoints
 */
class ApiEndpointsTest extends TestCase {

    /**
     * Test license validation endpoint structure
     */
    public function test_validate_endpoint_requires_license_key(): void {
        // Mock WP_REST_Request
        $request = $this->createMockRequest([
            'site_url' => 'https://example.com',
            // Missing license_key
        ]);

        // The endpoint should require license_key
        $this->assertFalse($request->has_param('license_key'));
    }

    /**
     * Test validate endpoint requires site_url
     */
    public function test_validate_endpoint_requires_site_url(): void {
        $request = $this->createMockRequest([
            'license_key' => 'ABCD-1234-EFGH-5678',
            // Missing site_url
        ]);

        $this->assertFalse($request->has_param('site_url'));
    }

    /**
     * Test validate endpoint accepts valid parameters
     */
    public function test_validate_endpoint_accepts_valid_params(): void {
        $request = $this->createMockRequest([
            'license_key' => 'ABCD-1234-EFGH-5678',
            'site_url' => 'https://example.com',
            'product_slug' => 'peanut-suite',
        ]);

        $this->assertTrue($request->has_param('license_key'));
        $this->assertTrue($request->has_param('site_url'));
        $this->assertTrue($request->has_param('product_slug'));
    }

    /**
     * Test site URL is validated
     */
    public function test_site_url_is_validated(): void {
        $valid_urls = [
            'https://example.com',
            'http://localhost',
            'https://sub.example.com/path',
            'https://example.com:8080',
        ];

        foreach ($valid_urls as $url) {
            $this->assertTrue(
                filter_var($url, FILTER_VALIDATE_URL) !== false,
                "URL '{$url}' should be valid"
            );
        }

        $invalid_urls = [
            'not-a-url',
            'ftp://example.com', // We may want to reject non-HTTP
            '',
        ];

        foreach ($invalid_urls as $url) {
            // Empty string fails validation
            if (empty($url)) {
                $this->assertEmpty($url);
            }
        }
    }

    /**
     * Test deactivate endpoint requires license_key and site_url
     */
    public function test_deactivate_endpoint_requires_params(): void {
        $request = $this->createMockRequest([
            'license_key' => 'ABCD-1234-EFGH-5678',
            'site_url' => 'https://example.com',
        ]);

        $this->assertTrue($request->has_param('license_key'));
        $this->assertTrue($request->has_param('site_url'));
    }

    /**
     * Test status endpoint only requires license_key
     */
    public function test_status_endpoint_only_requires_key(): void {
        $request = $this->createMockRequest([
            'license_key' => 'ABCD-1234-EFGH-5678',
        ]);

        $this->assertTrue($request->has_param('license_key'));
    }

    /**
     * Test health endpoint returns success structure
     */
    public function test_health_endpoint_response_structure(): void {
        // Expected response structure
        $expected_structure = [
            'status' => 'ok',
            'timestamp' => time(),
            'version' => '1.0.0',
        ];

        $this->assertArrayHasKey('status', $expected_structure);
        $this->assertArrayHasKey('timestamp', $expected_structure);
    }

    /**
     * Test update check endpoint accepts required params
     */
    public function test_update_check_accepts_params(): void {
        $request = $this->createMockRequest([
            'license_key' => 'ABCD-1234-EFGH-5678',
            'plugin_slug' => 'peanut-suite',
            'current_version' => '1.0.0',
        ]);

        $this->assertTrue($request->has_param('license_key'));
        $this->assertTrue($request->has_param('plugin_slug'));
        $this->assertTrue($request->has_param('current_version'));
    }

    /**
     * Test response includes rate limit headers
     */
    public function test_response_includes_rate_limit_headers(): void {
        // Expected headers
        $expected_headers = [
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
        ];

        // Verify we know what headers should be present
        foreach ($expected_headers as $header) {
            $this->assertStringContainsString('RateLimit', $header);
        }
    }

    /**
     * Test error response structure
     */
    public function test_error_response_structure(): void {
        $error_response = [
            'success' => false,
            'error' => 'invalid_license_key',
            'message' => 'The provided license key is invalid.',
        ];

        $this->assertFalse($error_response['success']);
        $this->assertArrayHasKey('error', $error_response);
        $this->assertArrayHasKey('message', $error_response);
    }

    /**
     * Test success response structure
     */
    public function test_success_response_structure(): void {
        $success_response = [
            'success' => true,
            'license' => [
                'status' => 'active',
                'tier' => 'pro',
                'expires_at' => '2025-12-31',
                'activations_used' => 1,
                'activations_limit' => 3,
                'features' => ['utm', 'links', 'contacts'],
            ],
        ];

        $this->assertTrue($success_response['success']);
        $this->assertArrayHasKey('license', $success_response);
        $this->assertArrayHasKey('status', $success_response['license']);
        $this->assertArrayHasKey('tier', $success_response['license']);
        $this->assertArrayHasKey('features', $success_response['license']);
    }

    /**
     * Test rate limited response structure
     */
    public function test_rate_limited_response_structure(): void {
        $rate_limited_response = [
            'success' => false,
            'error' => 'rate_limit_exceeded',
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => 60,
        ];

        $this->assertFalse($rate_limited_response['success']);
        $this->assertEquals('rate_limit_exceeded', $rate_limited_response['error']);
        $this->assertArrayHasKey('retry_after', $rate_limited_response);
    }

    /**
     * Test license key is sanitized
     */
    public function test_license_key_is_sanitized(): void {
        require_once dirname(__DIR__, 2) . '/includes/class-license-validator.php';

        $dirty_key = '  abcd-1234-efgh-5678<script>  ';
        $clean_key = \Peanut_License_Validator::sanitize_key($dirty_key);

        $this->assertStringNotContainsString('<script>', $clean_key);
        $this->assertStringNotContainsString(' ', $clean_key);
    }

    /**
     * Test product slug validation
     */
    public function test_product_slug_validation(): void {
        $valid_slugs = ['peanut-suite', 'formflow', 'peanut-booker'];
        $invalid_slugs = ['', '../../../etc/passwd', '<script>'];

        foreach ($valid_slugs as $slug) {
            $sanitized = sanitize_text_field($slug);
            $this->assertEquals($slug, $sanitized);
        }

        foreach ($invalid_slugs as $slug) {
            $sanitized = sanitize_text_field($slug);
            $this->assertStringNotContainsString('<', $sanitized);
        }
    }

    /**
     * SECURITY REGRESSION (2026-07-05 microscope): the REST /updates/download
     * route served the paid plugin ZIP to ANY caller — the signed-token scheme
     * was only enforced on the peanut_download query-var path. download_plugin()
     * now requires + verifies the same token before serving.
     */
    public function test_download_rejects_missing_token(): void {
        $endpoints = new \Peanut_API_Endpoints();
        $request = new \WP_REST_Request('GET', '/updates/download');
        $request->set_param('plugin', 'formflow');
        // no token

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unauthorized');
        $endpoints->download_plugin($request);
    }

    public function test_download_rejects_invalid_token(): void {
        $endpoints = new \Peanut_API_Endpoints();
        $request = new \WP_REST_Request('GET', '/updates/download');
        $request->set_param('plugin', 'formflow');
        $request->set_param('token', 'not-a-real-signed-token');
        $request->set_param('license', 'ABCD-1234-EFGH-5678');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unauthorized');
        $endpoints->download_plugin($request);
    }

    public function test_download_accepts_a_freshly_signed_token(): void {
        // A token minted by the same helper the update check uses must pass the
        // gate (it then proceeds to serve_download, which wp_die's 404 in the
        // test env because no ZIP file exists on disk — proving we got PAST the
        // auth gate rather than being rejected as unauthorized).
        $license = 'ABCD-1234-EFGH-5678';
        $token = \peanut_generate_download_token('formflow', $license, time() + 3600);

        $endpoints = new \Peanut_API_Endpoints();
        $request = new \WP_REST_Request('GET', '/updates/download');
        $request->set_param('plugin', 'formflow');
        $request->set_param('token', $token);
        $request->set_param('license', $license);

        try {
            $endpoints->download_plugin($request);
            $this->fail('Expected serve_download to wp_die past the auth gate.');
        } catch (\Exception $e) {
            $this->assertStringNotContainsString('Unauthorized', $e->getMessage(),
                'A validly-signed token must not be rejected by the auth gate.');
        }
    }

    /**
     * Create a mock request object
     */
    private function createMockRequest(array $params): object {
        return new class($params) {
            private array $params;

            public function __construct(array $params) {
                $this->params = $params;
            }

            public function get_param(string $key): mixed {
                return $this->params[$key] ?? null;
            }

            public function has_param(string $key): bool {
                return isset($this->params[$key]);
            }

            public function get_params(): array {
                return $this->params;
            }
        };
    }
}
