<?php
/**
 * Download Security Unit Tests (HMAC Token Authentication)
 *
 * Tests the signed download URL system that prevents unauthorized file
 * downloads and enumeration attacks. The token helpers live outside any class
 * (includes/download-token-functions.php) and are loaded by the mock-WordPress
 * bootstrap, so these run with no DB or real WP.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers ::peanut_generate_download_token
 * @covers ::peanut_verify_download_token
 * @covers ::peanut_get_download_secret
 */
class DownloadSecurityTest extends TestCase {

    /**
     * Test download token generation creates valid format
     */
    public function test_token_generation_format(): void {
        $plugin = 'peanut-suite';
        $license = 'ABCD-1234-EFGH-5678';

        $token = peanut_generate_download_token($plugin, $license);

        $this->assertNotEmpty($token, 'Token should not be empty');
        $this->assertIsString($token, 'Token should be a string');

        // Token should be base64 encoded
        $decoded = base64_decode($token, true);
        $this->assertNotFalse($decoded, 'Token should be valid base64');
    }

    /**
     * Test valid token verification passes
     */
    public function test_valid_token_verification(): void {
        $plugin = 'peanut-suite';
        $license = 'ABCD-1234-EFGH-5678';

        // Generate token
        $token = peanut_generate_download_token($plugin, $license);

        // Verify token
        $is_valid = peanut_verify_download_token($plugin, $token, $license);

        $this->assertTrue($is_valid, 'Valid token should pass verification');
    }

    /**
     * Test expired token fails verification
     */
    public function test_expired_token_fails(): void {
        $plugin = 'peanut-suite';
        $license = 'ABCD-1234-EFGH-5678';

        // Generate token that expired 1 hour ago
        $expired_time = time() - 3600;
        $token = peanut_generate_download_token($plugin, $license, $expired_time);

        $is_valid = peanut_verify_download_token($plugin, $token, $license);

        $this->assertFalse($is_valid, 'Expired token should fail verification');
    }

    /**
     * Test tampered token fails verification
     */
    public function test_tampered_token_fails(): void {
        $plugin = 'peanut-suite';
        $license = 'ABCD-1234-EFGH-5678';

        $token = peanut_generate_download_token($plugin, $license);

        // Tamper with the token
        $tampered_token = base64_encode('9999999999|fake_signature');

        $is_valid = peanut_verify_download_token($plugin, $tampered_token, $license);

        $this->assertFalse($is_valid, 'Tampered token should fail verification');
    }

    /**
     * Test token for wrong plugin fails
     */
    public function test_wrong_plugin_fails(): void {
        $plugin = 'peanut-suite';
        $wrong_plugin = 'peanut-booker';
        $license = 'ABCD-1234-EFGH-5678';

        $token = peanut_generate_download_token($plugin, $license);

        // Try to use token for different plugin
        $is_valid = peanut_verify_download_token($wrong_plugin, $token, $license);

        $this->assertFalse($is_valid, 'Token should not work for different plugin');
    }

    /**
     * Test token for wrong license fails
     */
    public function test_wrong_license_fails(): void {
        $plugin = 'peanut-suite';
        $license = 'ABCD-1234-EFGH-5678';
        $wrong_license = 'XXXX-0000-YYYY-9999';

        $token = peanut_generate_download_token($plugin, $license);

        // Try to use token with different license
        $is_valid = peanut_verify_download_token($plugin, $token, $wrong_license);

        $this->assertFalse($is_valid, 'Token should not work for different license');
    }

    /**
     * Test empty token fails
     */
    public function test_empty_token_fails(): void {
        $plugin = 'peanut-suite';

        $is_valid = peanut_verify_download_token($plugin, '', '');

        $this->assertFalse($is_valid, 'Empty token should fail');
    }

    /**
     * Test malformed token fails gracefully
     */
    public function test_malformed_token_fails_gracefully(): void {
        $plugin = 'peanut-suite';

        // Various malformed tokens
        $malformed_tokens = [
            'not-base64!!!',
            base64_encode('no-pipe-separator'),
            base64_encode('||too|many|pipes'),
            base64_encode(''),
        ];

        foreach ($malformed_tokens as $token) {
            $is_valid = peanut_verify_download_token($plugin, $token, '');
            $this->assertFalse($is_valid, "Malformed token '$token' should fail");
        }
    }

    /**
     * Test download secret is consistent
     */
    public function test_download_secret_consistency(): void {
        $secret1 = peanut_get_download_secret();
        $secret2 = peanut_get_download_secret();

        $this->assertEquals($secret1, $secret2, 'Download secret should be consistent');
        $this->assertNotEmpty($secret1, 'Download secret should not be empty');
    }

    /**
     * Test token expiration time is respected
     */
    public function test_custom_expiration_time(): void {
        $plugin = 'peanut-suite';
        $license = 'ABCD-1234-EFGH-5678';

        // Generate token with custom expiration (5 minutes from now)
        $expires = time() + 300;
        $token = peanut_generate_download_token($plugin, $license, $expires);

        // Should be valid now
        $is_valid = peanut_verify_download_token($plugin, $token, $license);
        $this->assertTrue($is_valid, 'Token should be valid before expiration');
    }

    /**
     * Test public downloads still require valid token format
     */
    public function test_public_download_requires_token(): void {
        $plugin = 'peanut-suite';

        // Attempt verification without proper token
        $is_valid = peanut_verify_download_token($plugin, 'random_string', '');

        $this->assertFalse($is_valid, 'Public downloads should still validate token format');
    }

    /**
     * Test different plugins get different tokens
     */
    public function test_tokens_are_plugin_specific(): void {
        $license = 'ABCD-1234-EFGH-5678';

        $token1 = peanut_generate_download_token('plugin-one', $license);
        $token2 = peanut_generate_download_token('plugin-two', $license);

        $this->assertNotEquals($token1, $token2, 'Different plugins should get different tokens');
    }

    /**
     * Test tokens are time-sensitive
     */
    public function test_tokens_are_time_sensitive(): void {
        $plugin = 'peanut-suite';
        $license = 'ABCD-1234-EFGH-5678';

        // Tokens generated at different times should differ
        $token1 = peanut_generate_download_token($plugin, $license, time() + 3600);
        $token2 = peanut_generate_download_token($plugin, $license, time() + 7200);

        $this->assertNotEquals($token1, $token2, 'Tokens with different expiration should differ');
    }
}
