<?php
/**
 * Trusted-Proxy Client IP Resolver
 *
 * Single source of truth for "what is the client's IP address?" used by every
 * throttle, IP block, audit log, and abuse heuristic in the plugin.
 *
 * SECURITY RATIONALE
 * ------------------
 * The HTTP_CF_CONNECTING_IP / HTTP_X_FORWARDED_FOR / HTTP_X_REAL_IP request
 * headers are attacker-controlled: any client can set them to an arbitrary
 * value. If we read them blindly (the old, duplicated behaviour), an attacker
 * can rotate their apparent identity at will and completely defeat every
 * rate-limit, IP-ban, and abuse-ML signal keyed on the client IP.
 *
 * The only trustworthy value is REMOTE_ADDR — the address of the peer that
 * actually opened the TCP connection to us. Forwarded headers are ONLY
 * meaningful when that immediate peer is a proxy WE control (e.g. Cloudflare,
 * an nginx front-end). So this resolver:
 *
 *   1. Defaults to REMOTE_ADDR.
 *   2. Consults forwarded headers ONLY when REMOTE_ADDR is in a configured
 *      trusted-proxy allowlist.
 *   3. Trusts NOTHING forwarded when no allowlist is configured.
 *
 * CONFIGURING THE ALLOWLIST (for legit Cloudflare / reverse-proxy setups)
 * ----------------------------------------------------------------------
 * Define the trusted proxy addresses/ranges (the peers that sit BETWEEN the
 * real client and PHP) via either:
 *
 *   // wp-config.php — CSV or array, single IPs and/or CIDR ranges:
 *   define('PEANUT_TRUSTED_PROXIES', '173.245.48.0/20, 2400:cb00::/32');
 *
 *   // ...or the 'peanut_trusted_proxies' option (same CSV/array shape).
 *
 * Cloudflare publishes its ranges at https://www.cloudflare.com/ips/ — list the
 * ones your traffic arrives from. Leave it UNSET on a direct-to-PHP host: then
 * REMOTE_ADDR is already the real client and no forwarded header is trusted.
 *
 * These helpers are dependency-light (no class, no DB required for the core
 * resolution path) so the unit suite can load them in isolation, mirroring
 * includes/download-token-functions.php.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

if (!function_exists('peanut_trusted_proxies')) {
    /**
     * Resolve the configured trusted-proxy allowlist.
     *
     * Accepts a comma-separated string or an array from either the
     * PEANUT_TRUSTED_PROXIES constant (preferred, set in wp-config.php) or the
     * 'peanut_trusted_proxies' option. Entries may be single IPs or CIDR ranges.
     *
     * @return array<int, string> Normalised, non-empty allowlist entries.
     */
    function peanut_trusted_proxies(): array {
        $proxies = null;

        if (defined('PEANUT_TRUSTED_PROXIES')) {
            $proxies = PEANUT_TRUSTED_PROXIES;
        } elseif (function_exists('get_option')) {
            $proxies = get_option('peanut_trusted_proxies', '');
        }

        if (is_string($proxies)) {
            $proxies = explode(',', $proxies);
        }

        if (!is_array($proxies)) {
            return [];
        }

        $out = [];
        foreach ($proxies as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $entry = trim($entry);
            if ($entry !== '') {
                $out[] = $entry;
            }
        }

        return $out;
    }
}

if (!function_exists('peanut_ip_in_cidr')) {
    /**
     * Test whether an IP falls within a CIDR range (or equals a bare IP).
     *
     * Handles both IPv4 and IPv6. A malformed input can never match.
     *
     * @param string $ip   Candidate IP address.
     * @param string $cidr Single IP or "subnet/bits" CIDR notation.
     * @return bool True when $ip is inside $cidr.
     */
    function peanut_ip_in_cidr(string $ip, string $cidr): bool {
        if (strpos($cidr, '/') === false) {
            // Bare address: constant-time exact compare.
            return hash_equals($cidr, $ip);
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        if ($bits === '' || !ctype_digit($bits)) {
            return false;
        }
        $bits = (int) $bits;

        $ip_bin     = @inet_pton($ip);
        $subnet_bin = @inet_pton($subnet);
        if ($ip_bin === false || $subnet_bin === false) {
            return false;
        }
        // Address families must match (both v4 = 4 bytes, or both v6 = 16 bytes).
        if (strlen($ip_bin) !== strlen($subnet_bin)) {
            return false;
        }

        $max_bits = strlen($ip_bin) * 8;
        if ($bits < 0 || $bits > $max_bits) {
            return false;
        }

        $whole_bytes = intdiv($bits, 8);
        $remainder   = $bits % 8;

        if ($whole_bytes > 0 && strncmp($ip_bin, $subnet_bin, $whole_bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;
        return (ord($ip_bin[$whole_bytes]) & $mask) === (ord($subnet_bin[$whole_bytes]) & $mask);
    }
}

if (!function_exists('peanut_is_trusted_proxy')) {
    /**
     * Is the given (immediate-peer) IP a configured trusted proxy?
     *
     * @param string                  $ip      Immediate peer IP (REMOTE_ADDR).
     * @param array<int, string>|null $trusted Allowlist override (defaults to the
     *                                         configured list). Enables isolated testing.
     * @return bool True when $ip is inside the trusted allowlist.
     */
    function peanut_is_trusted_proxy(string $ip, ?array $trusted = null): bool {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $trusted = $trusted ?? peanut_trusted_proxies();
        foreach ($trusted as $entry) {
            if (peanut_ip_in_cidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('peanut_get_client_ip')) {
    /**
     * Resolve the client IP, honouring forwarded headers ONLY behind a trusted proxy.
     *
     * Default (no allowlist, or peer not in it): returns the validated
     * REMOTE_ADDR — the address that actually connected to us. Attacker-supplied
     * CF-Connecting-IP / X-Forwarded-For / X-Real-IP headers are ignored.
     *
     * When REMOTE_ADDR is a configured trusted proxy, the left-most valid value
     * from the forwarded headers (in Cloudflare > XFF > X-Real-IP precedence) is
     * returned. Falls back to REMOTE_ADDR if none is valid.
     *
     * @param array<int, string>|null      $trusted_proxies Allowlist override (defaults to configured).
     * @param array<string, mixed>|null    $server          $_SERVER override (defaults to $_SERVER).
     * @return string The resolved client IP, or '0.0.0.0' when indeterminable.
     */
    function peanut_get_client_ip(?array $trusted_proxies = null, ?array $server = null): string {
        $server = $server ?? $_SERVER;

        $remote = isset($server['REMOTE_ADDR']) ? trim((string) $server['REMOTE_ADDR']) : '';
        $default = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';

        // Only the direct peer is trustworthy unless it is a configured proxy.
        if (!peanut_is_trusted_proxy($remote, $trusted_proxies)) {
            return $default;
        }

        // Behind a trusted proxy: the real client is in a forwarded header.
        $forwarded_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'];
        foreach ($forwarded_keys as $key) {
            if (empty($server[$key])) {
                continue;
            }
            $ip = (string) $server[$key];
            // X-Forwarded-For may be a chain "client, proxy1, proxy2" — take the
            // left-most (original client) entry.
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $default;
    }
}
