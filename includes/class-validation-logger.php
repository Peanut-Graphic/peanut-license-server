<?php
/**
 * Validation Logger Class
 *
 * Logs license validation attempts for security monitoring.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_Validation_Logger {

    /**
     * Get table name
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_validation_logs';
    }

    /**
     * Log a validation attempt
     */
    public static function log(array $data): int {
        global $wpdb;

        $insert_data = [
            'license_key_partial' => self::mask_license_key($data['license_key'] ?? ''),
            'license_key_hash' => isset($data['license_key']) ? hash('sha256', $data['license_key']) : null,
            'site_url' => sanitize_url($data['site_url'] ?? ''),
            'ip_address' => self::get_client_ip(),
            'user_agent' => substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'action' => sanitize_key($data['action'] ?? 'validate'),
            // Clamp to a valid ENUM('success','failed') member. Anything that
            // isn't an explicit success is recorded as 'failed' — inserting a
            // non-member value (the old 'unknown' default) errors under MySQL
            // STRICT mode on this per-validation hot path.
            'status' => self::clamp_status($data['status'] ?? null),
            'error_code' => sanitize_key($data['error_code'] ?? ''),
            'error_message' => sanitize_text_field($data['error_message'] ?? ''),
            'request_data' => wp_json_encode($data['request_data'] ?? []),
        ];

        $wpdb->insert(
            self::get_table_name(),
            $insert_data,
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $wpdb->insert_id;
    }

    /**
     * Clamp an arbitrary status to a valid ENUM('success','failed') member.
     *
     * Only an explicit 'success' is a success; everything else (including the
     * legacy 'unknown' default and any bogus value) is recorded as 'failed'.
     *
     * @param mixed $status Raw status from the caller.
     */
    private static function clamp_status($status): string {
        return sanitize_key((string) ($status ?? '')) === 'success' ? 'success' : 'failed';
    }

    /**
     * Log successful validation
     */
    public static function log_success(string $license_key, string $site_url, string $action = 'validate'): int {
        return self::log([
            'license_key' => $license_key,
            'site_url' => $site_url,
            'action' => $action,
            'status' => 'success',
        ]);
    }

    /**
     * Log failed validation
     */
    public static function log_failure(string $license_key, string $site_url, string $error_code, string $error_message, string $action = 'validate'): int {
        return self::log([
            'license_key' => $license_key,
            'site_url' => $site_url,
            'action' => $action,
            'status' => 'failed',
            'error_code' => $error_code,
            'error_message' => $error_message,
        ]);
    }

    /**
     * Get recent failed attempts for an IP
     */
    public static function get_failed_attempts_by_ip(string $ip, int $minutes = 60): int {
        global $wpdb;
        $table = self::get_table_name();

        $since = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE ip_address = %s AND status = 'failed' AND created_at >= %s",
            $ip,
            $since
        ));
    }

    /**
     * Get recent failed attempts for a license key
     */
    public static function get_failed_attempts_by_key(string $license_key_hash, int $minutes = 60): int {
        global $wpdb;
        $table = self::get_table_name();

        $since = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE license_key_hash = %s AND status = 'failed' AND created_at >= %s",
            $license_key_hash,
            $since
        ));
    }

    /**
     * Check if IP is suspicious (many failed attempts)
     */
    public static function is_suspicious_ip(?string $ip = null, int $threshold = 10, int $minutes = 60): bool {
        $ip = $ip ?: self::get_client_ip();
        return self::get_failed_attempts_by_ip($ip, $minutes) >= $threshold;
    }

    /**
     * Get validation logs with pagination
     */
    public static function get_logs(array $args = []): array {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = [
            'page' => 1,
            'per_page' => 50,
            'status' => '',
            'action' => '',
            'ip_address' => '',
            'date_from' => '',
            'date_to' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $offset = ($args['page'] - 1) * $args['per_page'];

        $where_clauses = ['1=1'];
        $where_values = [];

        if (!empty($args['status'])) {
            $where_clauses[] = 'status = %s';
            $where_values[] = $args['status'];
        }

        if (!empty($args['action'])) {
            $where_clauses[] = 'action = %s';
            $where_values[] = $args['action'];
        }

        if (!empty($args['ip_address'])) {
            $where_clauses[] = 'ip_address = %s';
            $where_values[] = $args['ip_address'];
        }

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }

        $where = implode(' AND ', $where_clauses);
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']) ?: 'created_at DESC';

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        if (!empty($where_values)) {
            $count_sql = $wpdb->prepare($count_sql, ...$where_values);
        }
        $total = (int) $wpdb->get_var($count_sql);

        // Get results
        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} LIMIT %d OFFSET %d";
        $values = array_merge($where_values, [$args['per_page'], $offset]);
        $logs = $wpdb->get_results($wpdb->prepare($sql, ...$values));

        return [
            'data' => $logs,
            'total' => $total,
            'page' => $args['page'],
            'per_page' => $args['per_page'],
            'total_pages' => ceil($total / $args['per_page']),
        ];
    }

    /**
     * Get security statistics
     */
    public static function get_statistics(int $days = 30): array {
        global $wpdb;
        $table = self::get_table_name();

        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = [
            'total_attempts' => 0,
            'successful' => 0,
            'failed' => 0,
            'unique_ips' => 0,
            'suspicious_ips' => [],
            'common_errors' => [],
            'by_day' => [],
        ];

        // Total counts
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                COUNT(DISTINCT ip_address) as unique_ips
            FROM {$table}
            WHERE created_at >= %s",
            $since
        ));

        $stats['total_attempts'] = (int) ($counts->total ?? 0);
        $stats['successful'] = (int) ($counts->successful ?? 0);
        $stats['failed'] = (int) ($counts->failed ?? 0);
        $stats['unique_ips'] = (int) ($counts->unique_ips ?? 0);

        // Suspicious IPs (>10 failed attempts)
        $suspicious = $wpdb->get_results($wpdb->prepare(
            "SELECT ip_address, COUNT(*) as count
            FROM {$table}
            WHERE status = 'failed' AND created_at >= %s
            GROUP BY ip_address
            HAVING count >= 10
            ORDER BY count DESC
            LIMIT 20",
            $since
        ));

        foreach ($suspicious as $row) {
            $stats['suspicious_ips'][$row->ip_address] = (int) $row->count;
        }

        // Common errors
        $errors = $wpdb->get_results($wpdb->prepare(
            "SELECT error_code, COUNT(*) as count
            FROM {$table}
            WHERE status = 'failed' AND error_code != '' AND created_at >= %s
            GROUP BY error_code
            ORDER BY count DESC
            LIMIT 10",
            $since
        ));

        foreach ($errors as $row) {
            $stats['common_errors'][$row->error_code] = (int) $row->count;
        }

        // By day
        $daily = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, status, COUNT(*) as count
            FROM {$table}
            WHERE created_at >= %s
            GROUP BY DATE(created_at), status
            ORDER BY date ASC",
            $since
        ));

        foreach ($daily as $row) {
            if (!isset($stats['by_day'][$row->date])) {
                $stats['by_day'][$row->date] = ['success' => 0, 'failed' => 0];
            }
            $stats['by_day'][$row->date][$row->status] = (int) $row->count;
        }

        return $stats;
    }

    /**
     * Cleanup old logs
     */
    public static function cleanup(int $days = 90): int {
        global $wpdb;
        $table = self::get_table_name();

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff
        ));

        return $result !== false ? $result : 0;
    }

    /**
     * Mask license key for logging (show first and last 4 chars)
     */
    private static function mask_license_key(string $key): string {
        if (strlen($key) < 10) {
            return '****';
        }

        return substr($key, 0, 4) . '-****-****-' . substr($key, -4);
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
     * Create the validation logs table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            license_key_partial VARCHAR(32) DEFAULT NULL,
            license_key_hash VARCHAR(64) DEFAULT NULL,
            site_url VARCHAR(255) DEFAULT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            action VARCHAR(32) DEFAULT 'validate',
            status ENUM('success', 'failed') DEFAULT 'failed',
            error_code VARCHAR(64) DEFAULT NULL,
            error_message VARCHAR(255) DEFAULT NULL,
            request_data TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ip_address (ip_address),
            KEY idx_license_key_hash (license_key_hash),
            KEY idx_status (status),
            KEY idx_created_at (created_at),
            KEY idx_ip_status_date (ip_address, status, created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
}
