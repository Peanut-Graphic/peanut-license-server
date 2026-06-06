<?php
/**
 * Download Token Helpers
 *
 * Pure HMAC-SHA256 signing helpers for the signed download URL system that
 * prevents unauthorized file downloads and enumeration attacks.
 *
 * These functions are dependency-light (hash_hmac / base64) so they can be
 * loaded in isolation by the unit test bootstrap without booting the rest of
 * the plugin. They are required by peanut-license-server.php before the
 * immediate download handler runs.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

if (!function_exists('peanut_get_download_secret')) {
    /**
     * Get the download signing secret.
     * Falls back to AUTH_KEY if no dedicated secret is set.
     *
     * @return string The signing secret.
     */
    function peanut_get_download_secret(): string {
        // Use dedicated secret if available, otherwise fall back to AUTH_KEY
        if (defined('PEANUT_DOWNLOAD_SECRET') && !empty(PEANUT_DOWNLOAD_SECRET)) {
            return PEANUT_DOWNLOAD_SECRET;
        }

        // Fall back to WordPress AUTH_KEY
        if (defined('AUTH_KEY') && AUTH_KEY !== 'put your unique phrase here') {
            return AUTH_KEY;
        }

        // Last resort: use a site-specific fallback (not ideal but better than nothing)
        // This requires WordPress to be partially loaded
        if (function_exists('get_site_url')) {
            return 'peanut_dl_' . md5(get_site_url() . ABSPATH);
        }

        return 'peanut_download_fallback_key';
    }
}

if (!function_exists('peanut_generate_download_token')) {
    /**
     * Generate a secure download token.
     *
     * @param string $plugin Plugin slug.
     * @param string $license License key (optional).
     * @param int $expires Expiration timestamp.
     * @return string The signed token.
     */
    function peanut_generate_download_token(string $plugin, string $license = '', int $expires = 0): string {
        if ($expires === 0) {
            $expires = time() + HOUR_IN_SECONDS; // 1 hour validity
        }

        $data = $plugin . '|' . $expires . '|' . $license;
        $signature = hash_hmac('sha256', $data, peanut_get_download_secret());

        return base64_encode($expires . '|' . $signature);
    }
}

if (!function_exists('peanut_verify_download_token')) {
    /**
     * Verify a download token.
     *
     * @param string $plugin Plugin slug.
     * @param string $token The token to verify.
     * @param string $license License key (optional).
     * @return bool True if valid, false otherwise.
     */
    function peanut_verify_download_token(string $plugin, string $token, string $license = ''): bool {
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        $parts = explode('|', $decoded, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$expires, $provided_signature] = $parts;

        // Check expiration
        if (!is_numeric($expires) || (int) $expires < time()) {
            return false;
        }

        // Regenerate signature and compare
        $data = $plugin . '|' . $expires . '|' . $license;
        $expected_signature = hash_hmac('sha256', $data, peanut_get_download_secret());

        return hash_equals($expected_signature, $provided_signature);
    }
}
