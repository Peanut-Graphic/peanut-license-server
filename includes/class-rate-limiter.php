<?php
/**
 * Rate Limiter Class
 *
 * Prevents API abuse by limiting request frequency per IP/license.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_Rate_Limiter {

    /**
     * Rate limit configurations per endpoint type
     */
    private const LIMITS = [
        'license_validate' => [
            'requests' => 10,
            'window' => 60, // 10 requests per minute
        ],
        'license_status' => [
            'requests' => 30,
            'window' => 60, // 30 requests per minute
        ],
        'update_check' => [
            'requests' => 60,
            'window' => 60, // 60 requests per minute
        ],
        'download' => [
            'requests' => 5,
            'window' => 300, // 5 downloads per 5 minutes
        ],
        'site_health' => [
            'requests' => 10,
            'window' => 60, // 10 health reports per minute (daily reports expected)
        ],
        'license_activations' => [
            'requests' => 20,
            'window' => 60, // 20 lookups per minute (prevents enumeration)
        ],
        'default' => [
            'requests' => 60,
            'window' => 60, // 60 requests per minute
        ],
    ];

    /**
     * Transient prefix
     */
    private const TRANSIENT_PREFIX = 'peanut_rate_';

    /**
     * Check if request should be rate limited
     */
    public static function is_rate_limited(string $endpoint_type, ?string $identifier = null): bool {
        $key = self::get_rate_key($endpoint_type, $identifier);
        $limit = self::LIMITS[$endpoint_type] ?? self::LIMITS['default'];

        $data = get_transient($key);

        if ($data === false) {
            return false;
        }

        return $data['count'] >= $limit['requests'];
    }

    /**
     * Record a request
     */
    public static function record_request(string $endpoint_type, ?string $identifier = null): void {
        $key = self::get_rate_key($endpoint_type, $identifier);
        $limit = self::LIMITS[$endpoint_type] ?? self::LIMITS['default'];

        $data = get_transient($key);

        if ($data === false) {
            $data = [
                'count' => 0,
                'first_request' => time(),
            ];
        }

        $data['count']++;
        $data['last_request'] = time();

        // Calculate remaining time in window
        $elapsed = time() - $data['first_request'];
        $remaining = max(1, $limit['window'] - $elapsed);

        set_transient($key, $data, $remaining);
    }

    /**
     * Get rate limit info for response headers
     */
    public static function get_rate_info(string $endpoint_type, ?string $identifier = null): array {
        $key = self::get_rate_key($endpoint_type, $identifier);
        $limit = self::LIMITS[$endpoint_type] ?? self::LIMITS['default'];

        $data = get_transient($key);

        if ($data === false) {
            return [
                'limit' => $limit['requests'],
                'remaining' => $limit['requests'],
                'reset' => time() + $limit['window'],
            ];
        }

        $remaining = max(0, $limit['requests'] - $data['count']);
        $reset = $data['first_request'] + $limit['window'];

        return [
            'limit' => $limit['requests'],
            'remaining' => $remaining,
            'reset' => $reset,
        ];
    }

    /**
     * Get rate limit key
     */
    private static function get_rate_key(string $endpoint_type, ?string $identifier = null): string {
        $identifier = $identifier ?: self::get_client_ip();
        return self::TRANSIENT_PREFIX . md5($endpoint_type . '_' . $identifier);
    }

    /**
     * Get client IP address
     */
    private static function get_client_ip(): string {
        // Delegate to the shared trusted-proxy resolver so forwarded headers
        // (CF-Connecting-IP / X-Forwarded-For / X-Real-IP) are only honoured
        // behind a configured trusted proxy and cannot be spoofed to defeat
        // rate-limits / IP bans. See includes/client-ip-functions.php.
        return peanut_get_client_ip();
    }

    /**
     * Clear rate limit for identifier
     */
    public static function clear(string $endpoint_type, ?string $identifier = null): void {
        $key = self::get_rate_key($endpoint_type, $identifier);
        delete_transient($key);
    }

    /**
     * Check rate limit and return error response if limited
     */
    public static function check(string $endpoint_type, ?string $identifier = null): ?WP_REST_Response {
        if (self::is_rate_limited($endpoint_type, $identifier)) {
            $info = self::get_rate_info($endpoint_type, $identifier);

            $response = new WP_REST_Response([
                'success' => false,
                'error' => 'rate_limit_exceeded',
                'message' => __('Too many requests. Please try again later.', 'peanut-license-server'),
                'retry_after' => $info['reset'] - time(),
            ], 429);

            $response->header('X-RateLimit-Limit', $info['limit']);
            $response->header('X-RateLimit-Remaining', 0);
            $response->header('X-RateLimit-Reset', $info['reset']);
            $response->header('Retry-After', $info['reset'] - time());

            return $response;
        }

        return null;
    }

    /**
     * Add rate limit headers to response
     */
    public static function add_headers(WP_REST_Response $response, string $endpoint_type, ?string $identifier = null): WP_REST_Response {
        $info = self::get_rate_info($endpoint_type, $identifier);

        $response->header('X-RateLimit-Limit', $info['limit']);
        $response->header('X-RateLimit-Remaining', $info['remaining']);
        $response->header('X-RateLimit-Reset', $info['reset']);

        return $response;
    }
}
