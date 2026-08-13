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

if (!function_exists('peanut_is_path_within_roots')) {
    /**
     * Verify that an existing filesystem path resolves beneath an allowed root.
     *
     * Uses real paths and a directory-separator boundary so sibling prefixes
     * (for example uploads-evil beside uploads) and symlinks escaping a trusted
     * directory are rejected.
     *
     * @param string   $path          Existing file path.
     * @param string[] $allowed_roots Existing trusted directories.
     */
    function peanut_is_path_within_roots(string $path, array $allowed_roots): bool {
        $real_path = realpath($path);
        if ($real_path === false || !is_file($real_path)) {
            return false;
        }

        foreach ($allowed_roots as $root) {
            $real_root = realpath($root);
            if ($real_root === false || !is_dir($real_root)) {
                continue;
            }

            $prefix = rtrim($real_root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            if (str_starts_with($real_path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('peanut_get_download_secret')) {
    /**
     * Get the download signing secret.
     *
     * Requires a real, configured secret: a dedicated PEANUT_DOWNLOAD_SECRET or
     * the WordPress AUTH_KEY. If neither is configured this returns an empty
     * string and the token helpers FAIL CLOSED (generation yields no token,
     * verification rejects every token) rather than falling back to a
     * guessable, site-derived md5() or a hardcoded shared key — which would let
     * an attacker forge download tokens.
     *
     * @return string The signing secret, or '' when none is configured.
     */
    function peanut_get_download_secret(): string {
        // Test-only injection seam so the unit suite can exercise the
        // fail-closed path deterministically (defined constants such as
        // AUTH_KEY cannot be un-defined mid-run). This branch is dead in
        // production, where PEANUT_LICENSE_SERVER_TESTING is never defined.
        if (defined('PEANUT_LICENSE_SERVER_TESTING') && PEANUT_LICENSE_SERVER_TESTING
            && array_key_exists('__peanut_download_secret_override', $GLOBALS)) {
            return (string) $GLOBALS['__peanut_download_secret_override'];
        }

        // Dedicated secret takes precedence when configured.
        if (defined('PEANUT_DOWNLOAD_SECRET') && (string) PEANUT_DOWNLOAD_SECRET !== '') {
            return (string) PEANUT_DOWNLOAD_SECRET;
        }

        // Fall back to the WordPress AUTH_KEY (rejecting the shipped placeholder).
        if (defined('AUTH_KEY') && (string) AUTH_KEY !== '' && AUTH_KEY !== 'put your unique phrase here') {
            return (string) AUTH_KEY;
        }

        // No trusted secret configured: fail closed. Do NOT derive a guessable
        // key from the site URL/path or return a hardcoded fallback.
        return '';
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
        $secret = peanut_get_download_secret();

        // Fail closed: without a configured secret we cannot mint a trustworthy
        // token, so return an empty string rather than signing with a guessable
        // key. Callers treat an empty token as "no download link available".
        if ($secret === '') {
            return '';
        }

        if ($expires === 0) {
            $expires = time() + HOUR_IN_SECONDS; // 1 hour validity
        }

        $data = $plugin . '|' . $expires . '|' . $license;
        $signature = hash_hmac('sha256', $data, $secret);

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
        $secret = peanut_get_download_secret();

        // Fail closed: with no configured secret, reject every token instead of
        // validating against a guessable/hardcoded fallback.
        if ($secret === '') {
            return false;
        }

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
        $expected_signature = hash_hmac('sha256', $data, $secret);

        return hash_equals($expected_signature, $provided_signature);
    }
}
