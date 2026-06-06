<?php
/**
 * Tests for API Security (Permission Callbacks and Auth Bypass Prevention)
 *
 * Tests the API security system including IP blocking, suspicious activity
 * tracking, and permission callbacks.
 *
 * @package Peanut_License_Server\Tests
 */

namespace Peanut\LicenseServer\Tests\Unit;

use Peanut\LicenseServer\Tests\TestCase;

class ApiSecurityTest extends TestCase {

    /**
     * Test IP blocking works correctly
     */
    public function test_ip_blocking(): void {
        $this->assertFalse(
            \Peanut_API_Security::is_ip_blocked(),
            'IP should not be blocked initially'
        );

        \Peanut_API_Security::block_ip();

        $this->assertTrue(
            \Peanut_API_Security::is_ip_blocked(),
            'IP should be blocked after block_ip()'
        );
    }

    /**
     * Test suspicious activity tracking
     */
    public function test_suspicious_activity_tracking(): void {
        // Record multiple suspicious activities
        for ($i = 0; $i < 5; $i++) {
            \Peanut_API_Security::record_suspicious_activity('test_activity');
        }

        // Should not be blocked yet (threshold is 10)
        $this->assertFalse(
            \Peanut_API_Security::is_ip_blocked(),
            'IP should not be blocked before threshold'
        );
    }

    /**
     * Test automatic blocking after threshold
     */
    public function test_auto_block_after_threshold(): void {
        // Clear any previous state
        \Peanut_API_Security::clear_suspicious_activity();

        // Record enough activities to trigger blocking
        for ($i = 0; $i < 10; $i++) {
            \Peanut_API_Security::record_suspicious_activity('threshold_test');
        }

        $this->assertTrue(
            \Peanut_API_Security::is_ip_blocked(),
            'IP should be auto-blocked after reaching threshold'
        );
    }

    /**
     * Test public endpoint permission allows valid requests
     */
    public function test_public_endpoint_allows_valid_request(): void {
        // Clear any blocks
        \Peanut_API_Security::clear_suspicious_activity();

        // Create mock request
        $request = $this->createMockRequest([
            'license_key' => 'ABCD-1234-EFGH-5678',
            'site_url' => 'https://example.com',
        ]);

        $result = \Peanut_API_Security::permission_public_license($request);

        $this->assertTrue($result, 'Valid public request should be allowed');
    }

    /**
     * Test public endpoint rejects blocked IP
     */
    public function test_public_endpoint_rejects_blocked_ip(): void {
        \Peanut_API_Security::block_ip();

        $request = $this->createMockRequest([
            'license_key' => 'ABCD-1234-EFGH-5678',
        ]);

        $result = \Peanut_API_Security::permission_public_license($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('rest_forbidden', $result->get_error_code());
    }

    /**
     * Test invalid license format is rejected
     */
    public function test_invalid_license_format_rejected(): void {
        \Peanut_API_Security::clear_suspicious_activity();

        $request = $this->createMockRequest([
            'license_key' => 'invalid-license',
        ]);

        $result = \Peanut_API_Security::permission_public_license($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('rest_invalid_param', $result->get_error_code());
    }

    /**
     * Test invalid site URL is rejected
     */
    public function test_invalid_site_url_rejected(): void {
        \Peanut_API_Security::clear_suspicious_activity();

        $request = $this->createMockRequest([
            'license_key' => 'ABCD-1234-EFGH-5678',
            'site_url' => 'not-a-valid-url',
        ]);

        $result = \Peanut_API_Security::permission_public_license($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('rest_invalid_param', $result->get_error_code());
    }

    /**
     * Test admin permission requires authentication
     */
    public function test_admin_permission_requires_auth(): void {
        $this->setUserLoggedIn(false);
        $this->setUserCapability('manage_options', false);

        $request = $this->createMockRequest([]);

        $result = \Peanut_API_Security::permission_admin($request);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('rest_forbidden', $result->get_error_code());
    }

    /**
     * Test admin permission allows administrators
     */
    public function test_admin_permission_allows_admins(): void {
        $this->setUserLoggedIn(true);
        $this->setUserCapability('manage_options', true);

        $request = $this->createMockRequest([]);

        $result = \Peanut_API_Security::permission_admin($request);

        $this->assertTrue($result, 'Admin should be able to access admin endpoints');
    }

    /**
     * Test HMAC signature validation
     */
    public function test_signature_validation(): void {
        $secret = 'test_secret_key';
        $body = '{"action":"validate"}';
        $timestamp = time();

        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $request = $this->createMockRequest([], [
            'X-Peanut-Signature' => $signature,
            'X-Peanut-Timestamp' => $timestamp,
        ], $body);

        $is_valid = \Peanut_API_Security::validate_signature($request, $secret);

        $this->assertTrue($is_valid, 'Valid signature should pass');
    }

    /**
     * Test expired timestamp fails signature validation
     */
    public function test_expired_timestamp_fails(): void {
        $secret = 'test_secret_key';
        $body = '{"action":"validate"}';
        $old_timestamp = time() - 600; // 10 minutes ago

        $signature = hash_hmac('sha256', $old_timestamp . '.' . $body, $secret);

        $request = $this->createMockRequest([], [
            'X-Peanut-Signature' => $signature,
            'X-Peanut-Timestamp' => $old_timestamp,
        ], $body);

        $is_valid = \Peanut_API_Security::validate_signature($request, $secret);

        $this->assertFalse($is_valid, 'Expired timestamp should fail');
    }

    /**
     * Test wrong secret fails signature validation
     */
    public function test_wrong_secret_fails(): void {
        $correct_secret = 'correct_secret';
        $wrong_secret = 'wrong_secret';
        $body = '{"action":"validate"}';
        $timestamp = time();

        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $correct_secret);

        $request = $this->createMockRequest([], [
            'X-Peanut-Signature' => $signature,
            'X-Peanut-Timestamp' => $timestamp,
        ], $body);

        $is_valid = \Peanut_API_Security::validate_signature($request, $wrong_secret);

        $this->assertFalse($is_valid, 'Wrong secret should fail validation');
    }

    /**
     * Test missing signature header fails
     */
    public function test_missing_signature_fails(): void {
        $secret = 'test_secret';
        $request = $this->createMockRequest([]);

        $is_valid = \Peanut_API_Security::validate_signature($request, $secret);

        $this->assertFalse($is_valid, 'Missing signature should fail');
    }

    /**
     * Test readonly permission allows when not blocked
     */
    public function test_readonly_permission_allows_when_not_blocked(): void {
        \Peanut_API_Security::clear_suspicious_activity();

        $request = $this->createMockRequest([]);

        $result = \Peanut_API_Security::permission_public_readonly($request);

        $this->assertTrue($result, 'Readonly endpoint should be accessible');
    }

    /**
     * Test security statistics returns expected format
     */
    public function test_security_statistics_format(): void {
        $stats = \Peanut_API_Security::get_statistics();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('blocked_ips', $stats);
        $this->assertArrayHasKey('period_days', $stats);
    }

    /**
     * Helper to create a mock WP_REST_Request
     */
    private function createMockRequest(array $params, array $headers = [], string $body = ''): \WP_REST_Request {
        return new class($params, $headers, $body) extends \WP_REST_Request {
            private array $params;
            private array $headers;
            private string $body;

            public function __construct(array $params, array $headers, string $body) {
                $this->params = $params;
                $this->headers = $headers;
                $this->body = $body;
            }

            public function get_param(string $key) {
                return $this->params[$key] ?? null;
            }

            public function get_header(string $key): ?string {
                return $this->headers[$key] ?? null;
            }

            public function get_body(): string {
                return $this->body;
            }

            public function get_route(): string {
                return '/peanut-license-server/v1/test';
            }
        };
    }
}
