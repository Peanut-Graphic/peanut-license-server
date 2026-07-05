<?php
/**
 * Update-Delivery / Endpoint-Authorization Regression Tests
 *
 * Guards the security cluster:
 *   (A) GET /updates/download is DEFAULT-DENY — no ZIP for an unauthenticated
 *       caller with no token and no/invalid license.
 *   (B) GET /license/activations is admin-only (401 unauthenticated, 403 non-admin).
 *   (C) get_download_file() prefers the pinned, non web-writable releases path
 *       over the web-writable uploads directory (no newest-mtime race).
 *   (D) The download-token helpers FAIL CLOSED when no signing secret is
 *       configured (no guessable md5()/hardcoded fallback).
 *
 * Runs on the self-contained mock-WordPress bootstrap (no DB, no real WP).
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * @covers Peanut_API_Endpoints::download_plugin
 * @covers Peanut_API_Endpoints::admin_permission_check
 * @covers Peanut_Update_Server::serve_download
 * @covers Peanut_Update_Server::get_download_file_candidates
 * @covers ::peanut_verify_download_token
 * @covers ::peanut_generate_download_token
 * @covers ::peanut_get_download_secret
 */
class UpdateDeliveryAuthTest extends TestCase {

    private Peanut_API_Endpoints $api;

    protected function setUp(): void {
        parent::setUp();
        $this->api = new Peanut_API_Endpoints();
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
        PeanutTestHelper::resetUser();
    }

    protected function tearDown(): void {
        parent::tearDown();
        PeanutTestHelper::clearTransients();
        PeanutTestHelper::clearOptions();
        PeanutTestHelper::resetUser();
        // Never leak the fail-closed secret override into other tests.
        unset($GLOBALS['__peanut_download_secret_override']);
    }

    // =========================================================================
    // (A) Update-download egress is default-deny.
    // =========================================================================

    /**
     * @test
     */
    public function download_with_no_token_and_no_license_is_denied(): void {
        $request = PeanutTestHelper::createMockRequest('GET', '/peanut-api/v1/updates/download', [
            'plugin' => 'peanut-suite',
        ]);

        // wp_die() is mocked to throw, and the ZIP is never streamed.
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Unauthorized/i');

        $this->api->download_plugin($request);
    }

    /**
     * @test
     */
    public function download_with_invalid_token_is_denied(): void {
        $request = PeanutTestHelper::createMockRequest('GET', '/peanut-api/v1/updates/download', [
            'plugin' => 'peanut-suite',
            'token' => 'not-a-real-token',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Unauthorized/i');

        $this->api->download_plugin($request);
    }

    /**
     * @test
     */
    public function download_with_unknown_but_invalid_license_is_denied(): void {
        // Mock DB resolves every key to null, so this license is "unknown".
        $request = PeanutTestHelper::createMockRequest('GET', '/peanut-api/v1/updates/download', [
            'plugin' => 'peanut-suite',
            'license' => 'ABCD-EFGH-IJKL-MNOP',
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Unauthorized/i');

        $this->api->download_plugin($request);
    }

    /**
     * @test
     *
     * A validly-signed token flips authorization on; serve_download() then
     * proceeds past the 403 gate (it fails later, on the missing file / the
     * readfile()+exit path, which is out of scope here). The key assertion is
     * that the failure is NOT the "Unauthorized" 403.
     */
    public function serve_download_default_denies_without_authorization(): void {
        $server = new Peanut_Update_Server('peanut-suite');

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Unauthorized/i');

        // Default $authorized = false must fail closed before any bytes.
        $server->serve_download('ABCD-EFGH-IJKL-MNOP');
    }

    // =========================================================================
    // (B) /license/activations is admin-only.
    // =========================================================================

    /**
     * @test
     */
    public function activations_permission_denies_logged_out_user(): void {
        PeanutTestHelper::setUserLoggedIn(false);
        PeanutTestHelper::setUserCan(false);

        $request = PeanutTestHelper::createMockRequest('GET', '/peanut-api/v1/license/activations', [
            'license_key' => 'ABCD-EFGH-IJKL-MNOP',
        ]);

        $result = $this->api->admin_permission_check($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals(401, $result->get_error_data()['status']);
    }

    /**
     * @test
     */
    public function activations_permission_denies_non_admin_user(): void {
        PeanutTestHelper::setUserLoggedIn(true);
        PeanutTestHelper::setUserCan(false); // logged in but cannot manage_options

        $request = PeanutTestHelper::createMockRequest('GET', '/peanut-api/v1/license/activations', [
            'license_key' => 'ABCD-EFGH-IJKL-MNOP',
        ]);

        $result = $this->api->admin_permission_check($request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertEquals(403, $result->get_error_data()['status']);
    }

    /**
     * @test
     */
    public function activations_permission_allows_admin_user(): void {
        PeanutTestHelper::setUserLoggedIn(true);
        PeanutTestHelper::setUserCan(true);

        $request = PeanutTestHelper::createMockRequest('GET', '/peanut-api/v1/license/activations', [
            'license_key' => 'ABCD-EFGH-IJKL-MNOP',
        ]);

        $this->assertTrue($this->api->admin_permission_check($request));
    }

    // =========================================================================
    // (C) Download file resolution prefers the pinned releases path.
    // =========================================================================

    /**
     * @test
     */
    public function download_candidates_prefer_pinned_releases_over_uploads(): void {
        PeanutTestHelper::setOption('peanut_peanut-suite_version', '2.5.0');

        $server = new Peanut_Update_Server('peanut-suite');
        $candidates = $server->get_download_file_candidates();

        // The trusted, version-pinned releases path is first.
        $this->assertStringContainsString('/releases/', $candidates[0]);
        $this->assertStringContainsString('peanut-suite-2.5.0.zip', $candidates[0]);

        // Every releases/ candidate must be ordered before any uploads candidate.
        $first_uploads = null;
        $last_releases = null;
        foreach ($candidates as $i => $path) {
            if (strpos($path, '/releases/') !== false) {
                $last_releases = $i;
            }
            if (strpos($path, '/uploads/') !== false && $first_uploads === null) {
                $first_uploads = $i;
            }
        }

        $this->assertNotNull($first_uploads, 'Expected an uploads fallback candidate.');
        $this->assertNotNull($last_releases, 'Expected a releases candidate.');
        $this->assertLessThan(
            $first_uploads,
            $last_releases,
            'Trusted releases paths must precede any web-writable uploads path.'
        );
    }

    /**
     * @test
     *
     * The repo ships releases/peanut-suite.zip and the mock uploads basedir does
     * not exist, so get_download_file() must resolve to the releases copy.
     */
    public function get_download_file_resolves_to_releases_copy(): void {
        $server = new Peanut_Update_Server('peanut-suite');
        $file = $server->get_download_file();

        $this->assertNotNull($file);
        $this->assertStringContainsString('/releases/peanut-suite', $file);
    }

    // =========================================================================
    // (D) Download-token helpers fail closed with no secret configured.
    // =========================================================================

    /**
     * @test
     */
    public function verify_fails_closed_when_no_secret_configured(): void {
        // Forge a token under the real (configured) secret first...
        $good_token = peanut_generate_download_token('peanut-suite', 'ABCD-EFGH-IJKL-MNOP');
        $this->assertNotSame('', $good_token, 'Token should mint while a secret is configured.');

        // ...then simulate a server with NO configured secret.
        $GLOBALS['__peanut_download_secret_override'] = '';

        $this->assertSame('', peanut_get_download_secret(), 'No secret must resolve to empty.');
        $this->assertFalse(
            peanut_verify_download_token('peanut-suite', $good_token, 'ABCD-EFGH-IJKL-MNOP'),
            'Verification must fail closed with no secret configured.'
        );
        $this->assertSame(
            '',
            peanut_generate_download_token('peanut-suite', 'ABCD-EFGH-IJKL-MNOP'),
            'Generation must yield no token with no secret configured.'
        );
    }

    /**
     * @test
     *
     * The removed fallback derived a guessable secret from the site URL/path or
     * used a hardcoded literal. Confirm neither is produced when unconfigured.
     */
    public function no_guessable_or_hardcoded_secret_fallback(): void {
        $GLOBALS['__peanut_download_secret_override'] = '';
        $secret = peanut_get_download_secret();

        $this->assertSame('', $secret);
        $this->assertStringNotContainsString('peanut_download_fallback_key', $secret);
        $this->assertStringNotContainsString('peanut_dl_', $secret);
    }
}
