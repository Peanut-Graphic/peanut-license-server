<?php
/**
 * API Security Class
 *
 * Provides security checks and permission callbacks for REST API endpoints.
 * Implements rate limiting, request validation, and security logging.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_API_Security {

    /**
     * Suspicious activity threshold (failures per hour)
     */
    private const SUSPICIOUS_THRESHOLD = 10;

    /**
     * Blocked IP transient prefix
     */
    private const BLOCKED_PREFIX = 'peanut_blocked_ip_';

    /**
     * Block duration in seconds (1 hour)
     */
    private const BLOCK_DURATION = 3600;

    /**
     * Permission callback for public license validation endpoints.
     *
     * These endpoints are intentionally public (called from client WordPress sites)
     * but we still apply rate limiting and basic security checks.
     *
     * @param WP_REST_Request $request The request object.
     * @return bool|WP_Error True if allowed, WP_Error if blocked.
     */
    public static function permission_public_license(WP_REST_Request $request): bool|WP_Error {
        // Check if IP is blocked.
        if (self::is_ip_blocked()) {
            self::log_security_event('blocked_request', [
                'endpoint' => $request->get_route(),
                'reason' => 'IP temporarily blocked due to suspicious activity',
            ]);
            return new WP_Error(
                'rest_forbidden',
                __('Access temporarily blocked. Please try again later.', 'peanut-license-server'),
                ['status' => 403]
            );
        }

        // Validate license key format if present.
        $license_key = $request->get_param('license_key');
        if ($license_key && !Peanut_License_Validator::is_valid_format($license_key)) {
            self::record_suspicious_activity('invalid_license_format');
            return new WP_Error(
                'rest_invalid_param',
                __('Invalid license key format.', 'peanut-license-server'),
                ['status' => 400]
            );
        }

        // Validate site URL format if present.
        $site_url = $request->get_param('site_url');
        if ($site_url && !filter_var($site_url, FILTER_VALIDATE_URL)) {
            self::record_suspicious_activity('invalid_site_url');
            return new WP_Error(
                'rest_invalid_param',
                __('Invalid site URL.', 'peanut-license-server'),
                ['status' => 400]
            );
        }

        // ML-powered abuse detection (gracefully degrades if service unavailable)
        if (class_exists('Peanut_ML_Abuse_Detector')) {
            $ml_result = Peanut_ML_Abuse_Detector::enforce(
                $request->get_param('license_key') ?? '',
                self::get_client_ip(),
                isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : null,
                $request->get_param('site_url'),
                'validate'
            );
            if (is_wp_error($ml_result)) {
                return $ml_result;
            }
        }

        return true;
    }

    /**
     * Permission callback for public read-only endpoints (updates, health).
     *
     * @param WP_REST_Request $request The request object.
     * @return bool|WP_Error True if allowed, WP_Error if blocked.
     */
    public static function permission_public_readonly(WP_REST_Request $request): bool|WP_Error {
        // Check if IP is blocked.
        if (self::is_ip_blocked()) {
            return new WP_Error(
                'rest_forbidden',
                __('Access temporarily blocked.', 'peanut-license-server'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Permission callback for admin-only endpoints.
     *
     * @param WP_REST_Request $request The request object.
     * @return bool|WP_Error True if allowed, WP_Error if not authorized.
     */
    public static function permission_admin(WP_REST_Request $request): bool|WP_Error {
        if (!current_user_can('manage_options')) {
            return new WP_Error(
                'rest_forbidden',
                __('You do not have permission to access this endpoint.', 'peanut-license-server'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Check if current IP is blocked.
     *
     * @return bool True if blocked.
     */
    public static function is_ip_blocked(): bool {
        $ip = self::get_client_ip();
        $key = self::BLOCKED_PREFIX . md5($ip);
        return get_transient($key) !== false;
    }

    /**
     * Block an IP address temporarily.
     *
     * @param string|null $ip IP address to block (defaults to current).
     */
    public static function block_ip(?string $ip = null): void {
        $ip = $ip ?? self::get_client_ip();
        $key = self::BLOCKED_PREFIX . md5($ip);
        set_transient($key, time(), self::BLOCK_DURATION);

        self::log_security_event('ip_blocked', [
            'ip_hash' => md5($ip),
            'duration' => self::BLOCK_DURATION,
        ]);
    }

    /**
     * Record suspicious activity for an IP.
     *
     * @param string $type Activity type.
     */
    public static function record_suspicious_activity(string $type): void {
        $ip = self::get_client_ip();
        $key = 'peanut_suspicious_' . md5($ip);
        $data = get_transient($key) ?: ['count' => 0, 'types' => []];

        $data['count']++;
        $data['types'][] = $type;
        $data['last_activity'] = time();

        // Set transient for 1 hour.
        set_transient($key, $data, 3600);

        // Block IP if threshold exceeded.
        if ($data['count'] >= self::SUSPICIOUS_THRESHOLD) {
            self::block_ip($ip);
        }

        // Log the activity.
        self::log_security_event('suspicious_activity', [
            'type' => $type,
            'count' => $data['count'],
            'ip_hash' => substr(md5($ip), 0, 8),
        ]);
    }

    /**
     * Clear suspicious activity record for an IP.
     *
     * @param string|null $ip IP address (defaults to current).
     */
    public static function clear_suspicious_activity(?string $ip = null): void {
        $ip = $ip ?? self::get_client_ip();
        delete_transient('peanut_suspicious_' . md5($ip));
    }

    /**
     * Log a security event.
     *
     * @param string $event Event type.
     * @param array  $data  Event data.
     */
    public static function log_security_event(string $event, array $data = []): void {
        $log_entry = array_merge([
            'timestamp' => current_time('c'),
            'event' => $event,
            'ip_hash' => substr(md5(self::get_client_ip()), 0, 8),
            'user_agent' => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'),
        ], $data);

        error_log('Peanut License Server Security: ' . wp_json_encode($log_entry));

        // Fire action for external monitoring.
        do_action('peanut_license_server_security_event', $event, $data);
    }

    /**
     * Get client IP address.
     *
     * @return string Client IP.
     */
    private static function get_client_ip(): string {
        // Delegate to the shared trusted-proxy resolver so forwarded headers
        // (CF-Connecting-IP / X-Forwarded-For / X-Real-IP) are only honoured
        // behind a configured trusted proxy and cannot be spoofed to defeat
        // rate-limits / IP bans. See includes/client-ip-functions.php.
        return peanut_get_client_ip();
    }

    /**
     * Validate request signature (for webhook-style requests).
     *
     * @param WP_REST_Request $request The request object.
     * @param string          $secret  The shared secret.
     * @return bool True if valid.
     */
    public static function validate_signature(WP_REST_Request $request, string $secret): bool {
        $signature = $request->get_header('X-Peanut-Signature');
        if (!$signature) {
            return false;
        }

        $body = $request->get_body();
        $timestamp = $request->get_header('X-Peanut-Timestamp');

        // Check timestamp is within 5 minutes.
        if (!$timestamp || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Get security statistics for admin dashboard.
     *
     * @param int $days Number of days to look back.
     * @return array Statistics.
     */
    public static function get_statistics(int $days = 7): array {
        global $wpdb;

        // This would require a dedicated security log table for full implementation.
        // For now, return basic stats from transients.
        return [
            'blocked_ips' => self::count_blocked_ips(),
            'period_days' => $days,
        ];
    }

    /**
     * Count currently blocked IPs (approximate).
     *
     * @return int Count.
     */
    private static function count_blocked_ips(): int {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options}
             WHERE option_name LIKE %s",
            '_transient_' . self::BLOCKED_PREFIX . '%'
        ));

        return (int) $count;
    }
}
