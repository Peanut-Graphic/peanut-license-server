<?php
/**
 * License Validator Unit Tests
 *
 * Tests validation logic, activation limits, site verification, and error handling.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers Peanut_License_Validator
 */
class LicenseValidatorTest extends TestCase {

    private Peanut_License_Validator $validator;

    /**
     * Set up test fixtures
     */
    protected function setUp(): void {
        parent::setUp();
        $this->validator = new Peanut_License_Validator();
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
    }

    /**
     * Tear down test fixtures
     */
    protected function tearDown(): void {
        parent::tearDown();
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
    }

    // =========================================================================
    // License Key Format Validation Tests
    // =========================================================================

    /**
     * @test
     * @covers Peanut_License_Validator::is_valid_format
     */
    public function is_valid_format_accepts_correct_format(): void {
        $this->assertTrue(Peanut_License_Validator::is_valid_format('ABCD-EFGH-IJKL-MNOP'));
        $this->assertTrue(Peanut_License_Validator::is_valid_format('1234-5678-90AB-CDEF'));
        $this->assertTrue(Peanut_License_Validator::is_valid_format('A1B2-C3D4-E5F6-G7H8'));
    }

    /**
     * @test
     * @covers Peanut_License_Validator::is_valid_format
     */
    public function is_valid_format_accepts_lowercase(): void {
        $this->assertTrue(Peanut_License_Validator::is_valid_format('abcd-efgh-ijkl-mnop'));
    }

    /**
     * @test
     * @covers Peanut_License_Validator::is_valid_format
     */
    public function is_valid_format_rejects_wrong_segment_count(): void {
        $this->assertFalse(Peanut_License_Validator::is_valid_format('ABCD-EFGH-IJKL'));
        $this->assertFalse(Peanut_License_Validator::is_valid_format('ABCD-EFGH-IJKL-MNOP-QRST'));
        $this->assertFalse(Peanut_License_Validator::is_valid_format('ABCDEFGHIJKLMNOP'));
    }

    /**
     * @test
     * @covers Peanut_License_Validator::is_valid_format
     */
    public function is_valid_format_rejects_wrong_segment_length(): void {
        $this->assertFalse(Peanut_License_Validator::is_valid_format('ABC-EFGH-IJKL-MNOP'));
        $this->assertFalse(Peanut_License_Validator::is_valid_format('ABCDE-EFGH-IJKL-MNOP'));
        $this->assertFalse(Peanut_License_Validator::is_valid_format('ABCD-EFG-IJKL-MNOP'));
    }

    /**
     * @test
     * @covers Peanut_License_Validator::is_valid_format
     */
    public function is_valid_format_rejects_special_characters(): void {
        $this->assertFalse(Peanut_License_Validator::is_valid_format('ABCD-EFGH-IJKL-MNO!'));
        $this->assertFalse(Peanut_License_Validator::is_valid_format('AB@D-EFGH-IJKL-MNOP'));
        $this->assertFalse(Peanut_License_Validator::is_valid_format('ABCD_EFGH_IJKL_MNOP'));
    }

    /**
     * @test
     * @covers Peanut_License_Validator::is_valid_format
     */
    public function is_valid_format_rejects_empty_string(): void {
        $this->assertFalse(Peanut_License_Validator::is_valid_format(''));
    }

    /**
     * @test
     * @covers Peanut_License_Validator::is_valid_format
     */
    public function is_valid_format_rejects_spaces(): void {
        $this->assertFalse(Peanut_License_Validator::is_valid_format('ABCD EFGH IJKL MNOP'));
        $this->assertFalse(Peanut_License_Validator::is_valid_format(' ABCD-EFGH-IJKL-MNOP'));
        $this->assertFalse(Peanut_License_Validator::is_valid_format('ABCD-EFGH-IJKL-MNOP '));
    }

    // =========================================================================
    // License Key Sanitization Tests
    // =========================================================================

    /**
     * @test
     * @covers Peanut_License_Validator::sanitize_key
     */
    public function sanitize_key_converts_to_uppercase(): void {
        $sanitized = Peanut_License_Validator::sanitize_key('abcd-efgh-ijkl-mnop');

        $this->assertEquals('ABCD-EFGH-IJKL-MNOP', $sanitized);
    }

    /**
     * @test
     * @covers Peanut_License_Validator::sanitize_key
     */
    public function sanitize_key_trims_whitespace(): void {
        $sanitized = Peanut_License_Validator::sanitize_key('  ABCD-EFGH-IJKL-MNOP  ');

        $this->assertEquals('ABCD-EFGH-IJKL-MNOP', $sanitized);
    }

    /**
     * @test
     * @covers Peanut_License_Validator::sanitize_key
     */
    public function sanitize_key_removes_invalid_characters(): void {
        $sanitized = Peanut_License_Validator::sanitize_key('ABCD@EFGH#IJKL$MNOP');

        $this->assertEquals('ABCDEFGHIJKLMNOP', $sanitized);
    }

    /**
     * @test
     * @covers Peanut_License_Validator::sanitize_key
     */
    public function sanitize_key_preserves_hyphens(): void {
        $sanitized = Peanut_License_Validator::sanitize_key('ABCD-EFGH-IJKL-MNOP');

        $this->assertEquals('ABCD-EFGH-IJKL-MNOP', $sanitized);
    }

    /**
     * @test
     * @covers Peanut_License_Validator::sanitize_key
     */
    public function sanitize_key_handles_empty_string(): void {
        $sanitized = Peanut_License_Validator::sanitize_key('');

        $this->assertEquals('', $sanitized);
    }

    /**
     * @test
     * @covers Peanut_License_Validator::sanitize_key
     */
    public function sanitize_key_handles_mixed_case_with_spaces(): void {
        $sanitized = Peanut_License_Validator::sanitize_key('  AbCd-eFgH-iJkL-MnOp  ');

        $this->assertEquals('ABCD-EFGH-IJKL-MNOP', $sanitized);
    }

    // =========================================================================
    // Error Code Constants Tests
    // =========================================================================

    /**
     * @test
     */
    public function error_codes_are_defined(): void {
        $this->assertEquals('invalid_license_key', Peanut_License_Validator::ERROR_INVALID_KEY);
        $this->assertEquals('license_expired', Peanut_License_Validator::ERROR_EXPIRED);
        $this->assertEquals('license_suspended', Peanut_License_Validator::ERROR_SUSPENDED);
        $this->assertEquals('license_revoked', Peanut_License_Validator::ERROR_REVOKED);
        $this->assertEquals('activation_limit_reached', Peanut_License_Validator::ERROR_ACTIVATION_LIMIT);
        $this->assertEquals('invalid_site_url', Peanut_License_Validator::ERROR_INVALID_SITE);
        $this->assertEquals('server_error', Peanut_License_Validator::ERROR_SERVER);
    }

    // =========================================================================
    // Error Tracking Tests
    // =========================================================================

    /**
     * @test
     * @covers Peanut_License_Validator::get_last_error
     */
    public function get_last_error_returns_empty_string_initially(): void {
        $validator = new Peanut_License_Validator();

        $this->assertEquals('', $validator->get_last_error());
    }

    /**
     * @test
     * @covers Peanut_License_Validator::get_last_error_message
     */
    public function get_last_error_message_returns_empty_string_initially(): void {
        $validator = new Peanut_License_Validator();

        $this->assertEquals('', $validator->get_last_error_message());
    }

    // =========================================================================
    // Validate and Activate Tests (with mocked dependencies)
    // =========================================================================

    /**
     * @test
     * @covers Peanut_License_Validator::validate_and_activate
     */
    public function validate_and_activate_returns_error_for_empty_site_url(): void {
        $result = $this->validator->validate_and_activate('ABCD-EFGH-IJKL-MNOP', [
            'site_url' => '',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals(Peanut_License_Validator::ERROR_INVALID_SITE, $result['error']);
    }

    /**
     * @test
     * @covers Peanut_License_Validator::validate_and_activate
     */
    public function validate_and_activate_returns_error_for_invalid_site_url(): void {
        $result = $this->validator->validate_and_activate('ABCD-EFGH-IJKL-MNOP', [
            'site_url' => 'not-a-valid-url',
        ]);

        $this->assertFalse($result['success']);
        $this->assertEquals(Peanut_License_Validator::ERROR_INVALID_SITE, $result['error']);
    }

    /**
     * @test
     * @covers Peanut_License_Validator::validate_and_activate
     */
    public function validate_and_activate_returns_error_for_missing_site_url(): void {
        $result = $this->validator->validate_and_activate('ABCD-EFGH-IJKL-MNOP', []);

        $this->assertFalse($result['success']);
        $this->assertEquals(Peanut_License_Validator::ERROR_INVALID_SITE, $result['error']);
    }

    // =========================================================================
    // Validate Only Tests (with mocked dependencies)
    // =========================================================================

    /**
     * @test
     * @covers Peanut_License_Validator::validate_only
     */
    public function validate_only_returns_error_for_nonexistent_license(): void {
        // With mock database returning null, license won't be found
        $result = $this->validator->validate_only('ABCD-EFGH-IJKL-MNOP');

        $this->assertFalse($result['success']);
        $this->assertEquals(Peanut_License_Validator::ERROR_INVALID_KEY, $result['error']);
    }

    // =========================================================================
    // Deactivate Tests (with mocked dependencies)
    // =========================================================================

    /**
     * @test
     * @covers Peanut_License_Validator::deactivate
     */
    public function deactivate_returns_error_for_nonexistent_license(): void {
        $result = $this->validator->deactivate('ABCD-EFGH-IJKL-MNOP', 'https://example.com');

        $this->assertFalse($result['success']);
        $this->assertEquals(Peanut_License_Validator::ERROR_INVALID_KEY, $result['error']);
    }

    // =========================================================================
    // Response Format Tests
    // =========================================================================

    /**
     * @test
     */
    public function error_response_contains_required_fields(): void {
        $result = $this->validator->validate_and_activate('ABCD-EFGH-IJKL-MNOP', [
            'site_url' => '',
        ]);

        $this->assertArrayHasKey('success', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayHasKey('message', $result);
        $this->assertFalse($result['success']);
        $this->assertIsString($result['error']);
        $this->assertIsString($result['message']);
    }

    /**
     * @test
     */
    public function error_response_message_is_translated(): void {
        $result = $this->validator->validate_and_activate('ABCD-EFGH-IJKL-MNOP', [
            'site_url' => '',
        ]);

        // Message should be non-empty (from __() translation function)
        $this->assertNotEmpty($result['message']);
    }

    // =========================================================================
    // Site URL Validation Tests
    // =========================================================================

    /**
     * @test
     */
    public function validate_accepts_https_url(): void {
        // This will fail at license lookup, but should pass URL validation
        $result = $this->validator->validate_and_activate('ABCD-EFGH-IJKL-MNOP', [
            'site_url' => 'https://valid-site.example.com',
        ]);

        // Should fail at license lookup, not URL validation
        $this->assertNotEquals(Peanut_License_Validator::ERROR_INVALID_SITE, $result['error'] ?? '');
    }

    /**
     * @test
     */
    public function validate_accepts_http_url(): void {
        $result = $this->validator->validate_and_activate('ABCD-EFGH-IJKL-MNOP', [
            'site_url' => 'http://valid-site.example.com',
        ]);

        // Should fail at license lookup, not URL validation
        $this->assertNotEquals(Peanut_License_Validator::ERROR_INVALID_SITE, $result['error'] ?? '');
    }

    /**
     * @test
     */
    public function validate_accepts_url_with_path(): void {
        $result = $this->validator->validate_and_activate('ABCD-EFGH-IJKL-MNOP', [
            'site_url' => 'https://example.com/wordpress/',
        ]);

        // Should fail at license lookup, not URL validation
        $this->assertNotEquals(Peanut_License_Validator::ERROR_INVALID_SITE, $result['error'] ?? '');
    }

    /**
     * @test
     */
    public function validate_accepts_localhost(): void {
        $result = $this->validator->validate_and_activate('ABCD-EFGH-IJKL-MNOP', [
            'site_url' => 'http://localhost',
        ]);

        // Should fail at license lookup, not URL validation
        $this->assertNotEquals(Peanut_License_Validator::ERROR_INVALID_SITE, $result['error'] ?? '');
    }

    /**
     * @test
     */
    public function validate_accepts_localhost_with_port(): void {
        $result = $this->validator->validate_and_activate('ABCD-EFGH-IJKL-MNOP', [
            'site_url' => 'http://localhost:8888',
        ]);

        // Should fail at license lookup, not URL validation
        $this->assertNotEquals(Peanut_License_Validator::ERROR_INVALID_SITE, $result['error'] ?? '');
    }

    // =========================================================================
    // Integration with License Manager Tests
    // =========================================================================

    // =========================================================================
    // Cross-Site Disclosure Tests (least-disclosure of activated_sites)
    // =========================================================================

    /**
     * A /license/status or /license/validate response for a key with 2+
     * activated sites must NOT expose OTHER sites' identifying fields
     * (site_url / site_name) to the caller. Only aggregate seat counts and
     * non-identifying per-activation metadata may be returned.
     *
     * @test
     * @covers Peanut_License_Validator::format_license_response
     */
    public function license_response_does_not_leak_other_activated_sites_urls_or_names(): void {
        // Two sites activated under the same license key.
        $license = (object) [
            'id' => 42,
            'status' => 'active',
            'tier' => 'free',
            'expires_at' => null,
            'activations_count' => 2,
            'max_activations' => 5,
            'activations' => [
                (object) [
                    'site_url' => 'https://caller-site.example.com',
                    'site_name' => 'Caller Site',
                    'activated_at' => '2026-01-01 00:00:00',
                    'is_active' => 1,
                ],
                (object) [
                    'site_url' => 'https://other-customer.example.org',
                    'site_name' => 'Other Customer Site',
                    'activated_at' => '2026-02-02 00:00:00',
                    'is_active' => 1,
                ],
            ],
        ];

        // format_license_response is private; exercise it directly since it is
        // the exact payload builder shared by validate_only + validate_and_activate.
        $method = new ReflectionMethod(Peanut_License_Validator::class, 'format_license_response');
        $response = $method->invoke($this->validator, $license);

        // Seat-count semantics the SDK/admin rely on are preserved.
        $this->assertSame(2, $response['activations_used']);
        $this->assertSame(5, $response['activations_limit']);

        // The identifying fields of ANY site must not appear anywhere in the payload.
        $encoded = wp_json_encode($response);
        $this->assertStringNotContainsString('other-customer.example.org', $encoded);
        $this->assertStringNotContainsString('Other Customer Site', $encoded);
        // The caller's own identifying fields are likewise not echoed back.
        $this->assertStringNotContainsString('caller-site.example.com', $encoded);
        $this->assertStringNotContainsString('Caller Site', $encoded);

        // Per-activation rows carry only non-identifying metadata.
        $this->assertArrayHasKey('activated_sites', $response);
        foreach ($response['activated_sites'] as $row) {
            $this->assertArrayNotHasKey('site_url', $row);
            $this->assertArrayNotHasKey('site_name', $row);
        }
    }

    /**
     * @test
     */
    public function validator_uses_license_manager_for_key_lookup(): void {
        // The validate_only method should use Peanut_License_Manager::get_by_key
        // With our mock database, this should return null (license not found)
        $result = $this->validator->validate_only('TEST-KEY1-2345-6789');

        // Verify it attempted to look up the license and failed
        $this->assertFalse($result['success']);
        $this->assertEquals(Peanut_License_Validator::ERROR_INVALID_KEY, $result['error']);
    }
}
