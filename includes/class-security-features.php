<?php
/**
 * Security Features Class
 *
 * Handles IP whitelisting, domain locking, and hardware fingerprinting.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_Security_Features {

    /**
     * Get security restrictions table name
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_license_restrictions';
    }

    /**
     * Check if license passes all security checks
     */
    public static function validate_request(int $license_id, array $request_data): array {
        $checks = [
            'ip' => true,
            'domain' => true,
            'hardware' => true,
        ];

        $errors = [];

        // Get restrictions for this license
        $restrictions = self::get_license_restrictions($license_id);

        if (empty($restrictions)) {
            return ['valid' => true, 'checks' => $checks, 'errors' => []];
        }

        // IP Whitelist check
        if (!empty($restrictions['ip_whitelist'])) {
            $client_ip = self::get_client_ip();
            $checks['ip'] = self::check_ip_whitelist($client_ip, $restrictions['ip_whitelist']);

            if (!$checks['ip']) {
                $errors[] = sprintf(
                    __('IP address %s is not in the allowed list.', 'peanut-license-server'),
                    $client_ip
                );
            }
        }

        // Domain lock check
        if (!empty($restrictions['allowed_domains'])) {
            $site_url = $request_data['site_url'] ?? '';
            $checks['domain'] = self::check_domain_lock($site_url, $restrictions['allowed_domains']);

            if (!$checks['domain']) {
                $errors[] = sprintf(
                    __('Domain %s is not authorized for this license.', 'peanut-license-server'),
                    parse_url($site_url, PHP_URL_HOST)
                );
            }
        }

        // Hardware fingerprint check
        if (!empty($restrictions['hardware_id']) && !empty($request_data['hardware_id'])) {
            $checks['hardware'] = self::check_hardware_fingerprint(
                $request_data['hardware_id'],
                $restrictions['hardware_id']
            );

            if (!$checks['hardware']) {
                $errors[] = __('Hardware fingerprint does not match.', 'peanut-license-server');
            }
        }

        $valid = !in_array(false, $checks, true);

        return [
            'valid' => $valid,
            'checks' => $checks,
            'errors' => $errors,
        ];
    }

    /**
     * Check IP against whitelist
     */
    public static function check_ip_whitelist(string $ip, array $whitelist): bool {
        if (empty($whitelist)) {
            return true;
        }

        foreach ($whitelist as $allowed) {
            $allowed = trim($allowed);

            if (empty($allowed)) {
                continue;
            }

            // Exact match
            if ($ip === $allowed) {
                return true;
            }

            // CIDR notation (e.g., 192.168.1.0/24)
            if (strpos($allowed, '/') !== false) {
                if (self::ip_in_cidr($ip, $allowed)) {
                    return true;
                }
            }

            // Wildcard (e.g., 192.168.1.*)
            if (strpos($allowed, '*') !== false) {
                $pattern = '/^' . str_replace(['.', '*'], ['\.', '\d+'], $allowed) . '$/';
                if (preg_match($pattern, $ip)) {
                    return true;
                }
            }

            // Range (e.g., 192.168.1.1-192.168.1.100)
            if (strpos($allowed, '-') !== false) {
                list($start, $end) = explode('-', $allowed);
                if (self::ip_in_range($ip, trim($start), trim($end))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check domain against allowed domains
     */
    public static function check_domain_lock(string $site_url, array $allowed_domains): bool {
        if (empty($allowed_domains)) {
            return true;
        }

        $domain = parse_url($site_url, PHP_URL_HOST);

        if (empty($domain)) {
            return false;
        }

        $domain = strtolower($domain);

        foreach ($allowed_domains as $allowed) {
            $allowed = strtolower(trim($allowed));

            if (empty($allowed)) {
                continue;
            }

            // Exact match
            if ($domain === $allowed) {
                return true;
            }

            // Wildcard subdomain (e.g., *.example.com)
            if (strpos($allowed, '*.') === 0) {
                $base_domain = substr($allowed, 2);
                $suffix = '.' . $base_domain;
                $suffix_len = strlen($suffix);
                // Non-negative offset: only a suffix match when $domain is at least as long
                // as $suffix; substr() from a non-negative offset never leaks the whole string.
                if ($domain === $base_domain
                    || (strlen($domain) >= $suffix_len && substr($domain, strlen($domain) - $suffix_len) === $suffix)) {
                    return true;
                }
            }

            // Check if domain ends with allowed (for subdomain matching)
            $allowed_len = strlen($allowed);
            // Non-negative offset suffix match (avoids substr($s, -$n) leaking when $n == 0).
            if ($allowed_len > 0
                && strlen($domain) >= $allowed_len
                && substr($domain, strlen($domain) - $allowed_len) === $allowed) {
                // Make sure it's actually a subdomain, not just a partial match
                $prefix = substr($domain, 0, strlen($domain) - $allowed_len);
                if ($prefix === '' || substr($prefix, -1) === '.') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check hardware fingerprint
     */
    public static function check_hardware_fingerprint(string $provided_id, string $stored_id): bool {
        if (empty($stored_id)) {
            return true;
        }

        // Normalize and compare
        return hash_equals(
            strtolower(trim($stored_id)),
            strtolower(trim($provided_id))
        );
    }

    /**
     * Get restrictions for a license
     */
    public static function get_license_restrictions(int $license_id): ?array {
        global $wpdb;
        $table = self::get_table_name();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE license_id = %d",
            $license_id
        ));

        if (!$row) {
            return null;
        }

        return [
            'ip_whitelist' => $row->ip_whitelist ? json_decode($row->ip_whitelist, true) : [],
            'allowed_domains' => $row->allowed_domains ? json_decode($row->allowed_domains, true) : [],
            'hardware_id' => $row->hardware_id,
            'enforce_ip' => (bool) $row->enforce_ip,
            'enforce_domain' => (bool) $row->enforce_domain,
            'enforce_hardware' => (bool) $row->enforce_hardware,
        ];
    }

    /**
     * Set restrictions for a license
     */
    public static function set_license_restrictions(int $license_id, array $restrictions): bool {
        global $wpdb;
        $table = self::get_table_name();

        $data = [
            'license_id' => $license_id,
            'ip_whitelist' => !empty($restrictions['ip_whitelist'])
                ? wp_json_encode(array_filter(array_map('trim', $restrictions['ip_whitelist'])))
                : null,
            'allowed_domains' => !empty($restrictions['allowed_domains'])
                ? wp_json_encode(array_filter(array_map('trim', $restrictions['allowed_domains'])))
                : null,
            'hardware_id' => !empty($restrictions['hardware_id'])
                ? sanitize_text_field($restrictions['hardware_id'])
                : null,
            'enforce_ip' => !empty($restrictions['enforce_ip']) ? 1 : 0,
            'enforce_domain' => !empty($restrictions['enforce_domain']) ? 1 : 0,
            'enforce_hardware' => !empty($restrictions['enforce_hardware']) ? 1 : 0,
            'updated_at' => current_time('mysql'),
        ];

        // Check if restrictions exist
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE license_id = %d",
            $license_id
        ));

        if ($existing) {
            $result = $wpdb->update(
                $table,
                $data,
                ['license_id' => $license_id],
                ['%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s'],
                ['%d']
            );
        } else {
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert(
                $table,
                $data,
                ['%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s']
            );
        }

        return $result !== false;
    }

    /**
     * Remove restrictions for a license
     */
    public static function remove_license_restrictions(int $license_id): bool {
        global $wpdb;
        $table = self::get_table_name();

        $result = $wpdb->delete(
            $table,
            ['license_id' => $license_id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Add IP to whitelist
     */
    public static function add_ip_to_whitelist(int $license_id, string $ip): bool {
        $restrictions = self::get_license_restrictions($license_id) ?: [
            'ip_whitelist' => [],
            'allowed_domains' => [],
            'hardware_id' => null,
            'enforce_ip' => true,
            'enforce_domain' => false,
            'enforce_hardware' => false,
        ];

        if (!in_array($ip, $restrictions['ip_whitelist'])) {
            $restrictions['ip_whitelist'][] = $ip;
        }

        return self::set_license_restrictions($license_id, $restrictions);
    }

    /**
     * Remove IP from whitelist
     */
    public static function remove_ip_from_whitelist(int $license_id, string $ip): bool {
        $restrictions = self::get_license_restrictions($license_id);

        if (!$restrictions) {
            return true;
        }

        $restrictions['ip_whitelist'] = array_filter(
            $restrictions['ip_whitelist'],
            fn($item) => $item !== $ip
        );

        return self::set_license_restrictions($license_id, $restrictions);
    }

    /**
     * Add domain to allowed list
     */
    public static function add_allowed_domain(int $license_id, string $domain): bool {
        $restrictions = self::get_license_restrictions($license_id) ?: [
            'ip_whitelist' => [],
            'allowed_domains' => [],
            'hardware_id' => null,
            'enforce_ip' => false,
            'enforce_domain' => true,
            'enforce_hardware' => false,
        ];

        $domain = strtolower(trim($domain));

        if (!in_array($domain, $restrictions['allowed_domains'])) {
            $restrictions['allowed_domains'][] = $domain;
        }

        return self::set_license_restrictions($license_id, $restrictions);
    }

    /**
     * Remove domain from allowed list
     */
    public static function remove_allowed_domain(int $license_id, string $domain): bool {
        $restrictions = self::get_license_restrictions($license_id);

        if (!$restrictions) {
            return true;
        }

        $domain = strtolower(trim($domain));
        $restrictions['allowed_domains'] = array_filter(
            $restrictions['allowed_domains'],
            fn($item) => strtolower($item) !== $domain
        );

        return self::set_license_restrictions($license_id, $restrictions);
    }

    /**
     * Set hardware fingerprint
     */
    public static function set_hardware_fingerprint(int $license_id, string $hardware_id): bool {
        $restrictions = self::get_license_restrictions($license_id) ?: [
            'ip_whitelist' => [],
            'allowed_domains' => [],
            'hardware_id' => null,
            'enforce_ip' => false,
            'enforce_domain' => false,
            'enforce_hardware' => true,
        ];

        $restrictions['hardware_id'] = $hardware_id;
        $restrictions['enforce_hardware'] = true;

        return self::set_license_restrictions($license_id, $restrictions);
    }

    /**
     * Clear hardware fingerprint
     */
    public static function clear_hardware_fingerprint(int $license_id): bool {
        $restrictions = self::get_license_restrictions($license_id);

        if (!$restrictions) {
            return true;
        }

        $restrictions['hardware_id'] = null;
        $restrictions['enforce_hardware'] = false;

        return self::set_license_restrictions($license_id, $restrictions);
    }

    /**
     * Generate hardware fingerprint from server info
     * This should be called from the client plugin
     */
    public static function generate_hardware_fingerprint(array $server_info): string {
        // Components that make up the fingerprint
        $components = [
            $server_info['server_name'] ?? '',
            $server_info['server_addr'] ?? '',
            $server_info['document_root'] ?? '',
            $server_info['php_version'] ?? PHP_VERSION,
            $server_info['os'] ?? PHP_OS,
        ];

        // Create a hash of the combined components
        return hash('sha256', implode('|', $components));
    }

    /**
     * Check if IP is in CIDR range
     */
    private static function ip_in_cidr(string $ip, string $cidr): bool {
        list($subnet, $mask) = explode('/', $cidr);

        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask_long = -1 << (32 - (int) $mask);

        $subnet_long &= $mask_long;

        return ($ip_long & $mask_long) === $subnet_long;
    }

    /**
     * Check if IP is in range
     */
    private static function ip_in_range(string $ip, string $start, string $end): bool {
        $ip_long = ip2long($ip);
        $start_long = ip2long($start);
        $end_long = ip2long($end);

        return $ip_long >= $start_long && $ip_long <= $end_long;
    }

    /**
     * Get client IP
     */
    private static function get_client_ip(): string {
        // Delegate to the shared trusted-proxy resolver so forwarded headers
        // (CF-Connecting-IP / X-Forwarded-For / X-Real-IP) are only honoured
        // behind a configured trusted proxy and cannot be spoofed to defeat
        // rate-limits / IP bans. See includes/client-ip-functions.php.
        return peanut_get_client_ip();
    }

    /**
     * Create restrictions table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            license_id BIGINT UNSIGNED NOT NULL UNIQUE,
            ip_whitelist TEXT DEFAULT NULL,
            allowed_domains TEXT DEFAULT NULL,
            hardware_id VARCHAR(64) DEFAULT NULL,
            enforce_ip TINYINT(1) DEFAULT 0,
            enforce_domain TINYINT(1) DEFAULT 0,
            enforce_hardware TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_license_id (license_id),
            KEY idx_hardware_id (hardware_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get licenses with restrictions
     */
    public static function get_licenses_with_restrictions(): array {
        global $wpdb;
        $table = self::get_table_name();
        $licenses_table = $wpdb->prefix . 'peanut_licenses';

        return $wpdb->get_results("
            SELECT r.*, l.license_key, l.customer_email, l.tier, l.status
            FROM {$table} r
            JOIN {$licenses_table} l ON r.license_id = l.id
            ORDER BY r.updated_at DESC
        ");
    }
}
