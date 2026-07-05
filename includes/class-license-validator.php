<?php
/**
 * License Validator Class
 *
 * Handles license validation and activation logic.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_License_Validator {

    /**
     * Error codes
     */
    public const ERROR_INVALID_KEY = 'invalid_license_key';
    public const ERROR_EXPIRED = 'license_expired';
    public const ERROR_SUSPENDED = 'license_suspended';
    public const ERROR_REVOKED = 'license_revoked';
    public const ERROR_ACTIVATION_LIMIT = 'activation_limit_reached';
    public const ERROR_INVALID_SITE = 'invalid_site_url';
    public const ERROR_SERVER = 'server_error';

    /**
     * Cache TTL in seconds (5 minutes)
     */
    private const CACHE_TTL = 300;

    /**
     * Last error
     */
    private string $last_error = '';

    /**
     * Last error message
     */
    private string $last_error_message = '';

    /**
     * Validate and activate a license
     */
    public function validate_and_activate(string $license_key, array $site_data): array {
        // Validate site URL
        if (empty($site_data['site_url']) || !filter_var($site_data['site_url'], FILTER_VALIDATE_URL)) {
            return $this->error_response(
                self::ERROR_INVALID_SITE,
                __('Invalid site URL provided.', 'peanut-license-server')
            );
        }

        // Get license
        $license = Peanut_License_Manager::get_by_key($license_key);

        if (!$license) {
            return $this->error_response(
                self::ERROR_INVALID_KEY,
                __('Invalid license key.', 'peanut-license-server')
            );
        }

        // Check license status
        $status_check = $this->check_license_status($license);
        if (!$status_check['success']) {
            return $status_check;
        }

        // OPTIMIZED: Check if this site is already activated using direct database query
        // This avoids loading all activations and iterating in PHP (N+1 query fix)
        $existing_activation = Peanut_License_Manager::get_activation_by_site(
            $license->id,
            $site_data['site_url'],
            true // active only
        );

        // If not already activated, check activation limit using database count
        if (!$existing_activation) {
            $active_count = Peanut_License_Manager::count_active_activations($license->id);

            if ($active_count >= (int) $license->max_activations) {
                return $this->error_response(
                    self::ERROR_ACTIVATION_LIMIT,
                    sprintf(
                        __('Maximum activations (%d) reached. Please deactivate another site first.', 'peanut-license-server'),
                        $license->max_activations
                    )
                );
            }
        }

        // Add or update activation
        $activation = Peanut_License_Manager::add_activation($license->id, $site_data);

        if (!$activation) {
            return $this->error_response(
                self::ERROR_SERVER,
                __('Failed to activate license. Please try again.', 'peanut-license-server')
            );
        }

        // Refresh license data
        $license = Peanut_License_Manager::get_by_id($license->id);

        return $this->success_response($license);
    }

    /**
     * Validate license without activating (with caching)
     */
    public function validate_only(string $license_key): array {
        // Check cache first
        $cache_key = self::get_cache_key($license_key);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            Peanut_Logger::debug('License validation cache hit', [
                'cache_key' => $cache_key,
            ]);
            return $cached;
        }

        $license = Peanut_License_Manager::get_by_key($license_key);

        if (!$license) {
            $result = $this->error_response(
                self::ERROR_INVALID_KEY,
                __('Invalid license key.', 'peanut-license-server')
            );
            // Cache invalid key responses briefly (1 minute) to prevent brute force
            set_transient($cache_key, $result, 60);
            return $result;
        }

        $status_check = $this->check_license_status($license);
        if (!$status_check['success']) {
            // Cache error responses for shorter time (2 minutes)
            set_transient($cache_key, $status_check, 120);
            return $status_check;
        }

        $result = $this->success_response($license);

        // Cache successful validations
        set_transient($cache_key, $result, self::CACHE_TTL);

        Peanut_Logger::debug('License validation cached', [
            'license_id' => $license->id,
            'ttl' => self::CACHE_TTL,
        ]);

        return $result;
    }

    /**
     * Get cache key for license validation
     */
    public static function get_cache_key(string $license_key): string {
        return 'peanut_lic_' . md5($license_key);
    }

    /**
     * Invalidate cache for a license
     */
    public static function invalidate_cache(string $license_key): void {
        $cache_key = self::get_cache_key($license_key);
        delete_transient($cache_key);

        Peanut_Logger::debug('License cache invalidated', [
            'cache_key' => $cache_key,
        ]);
    }

    /**
     * Invalidate cache by license ID
     */
    public static function invalidate_cache_by_id(int $license_id): void {
        $license = Peanut_License_Manager::get_by_id($license_id);
        if ($license && !empty($license->license_key)) {
            self::invalidate_cache($license->license_key);
        }
    }

    /**
     * Deactivate a site
     */
    public function deactivate(string $license_key, string $site_url): array {
        $license = Peanut_License_Manager::get_by_key($license_key);

        if (!$license) {
            return $this->error_response(
                self::ERROR_INVALID_KEY,
                __('Invalid license key.', 'peanut-license-server')
            );
        }

        $result = Peanut_License_Manager::remove_activation($license->id, $site_url);

        if (!$result) {
            return $this->error_response(
                self::ERROR_SERVER,
                __('Failed to deactivate site.', 'peanut-license-server')
            );
        }

        // Refresh license data
        $license = Peanut_License_Manager::get_by_id($license->id);

        return [
            'success' => true,
            'message' => __('Site deactivated successfully.', 'peanut-license-server'),
            'license' => $this->format_license_response($license),
        ];
    }

    /**
     * Check license status
     */
    private function check_license_status(object $license): array {
        // Check expiration
        if ($license->expires_at && strtotime($license->expires_at) < time()) {
            // Update status if not already expired
            if ($license->status === 'active') {
                Peanut_License_Manager::update($license->id, ['status' => 'expired']);
            }

            return $this->error_response(
                self::ERROR_EXPIRED,
                sprintf(
                    __('License expired on %s. Please renew to continue using premium features.', 'peanut-license-server'),
                    date_i18n(get_option('date_format'), strtotime($license->expires_at))
                )
            );
        }

        // Check status
        switch ($license->status) {
            case 'suspended':
                return $this->error_response(
                    self::ERROR_SUSPENDED,
                    __('This license has been suspended. Please contact support.', 'peanut-license-server')
                );

            case 'revoked':
                return $this->error_response(
                    self::ERROR_REVOKED,
                    __('This license has been revoked.', 'peanut-license-server')
                );

            case 'expired':
                return $this->error_response(
                    self::ERROR_EXPIRED,
                    __('This license has expired. Please renew to continue using premium features.', 'peanut-license-server')
                );
        }

        return ['success' => true];
    }

    /**
     * Format success response
     */
    private function success_response(object $license): array {
        return [
            'success' => true,
            'license' => $this->format_license_response($license),
        ];
    }

    /**
     * Format error response
     */
    private function error_response(string $code, string $message): array {
        $this->last_error = $code;
        $this->last_error_message = $message;

        return [
            'success' => false,
            'error' => $code,
            'message' => $message,
        ];
    }

    /**
     * Format license data for API response
     */
    private function format_license_response(object $license): array {
        $tier_features = Peanut_License_Manager::get_tier_features($license->tier);

        return [
            'status' => $license->status,
            'tier' => $license->tier,
            'tier_name' => Peanut_License_Manager::TIERS[$license->tier]['name'] ?? 'Free',
            'expires_at' => $license->expires_at,
            'expires_at_formatted' => $license->expires_at
                ? date_i18n(get_option('date_format'), strtotime($license->expires_at))
                : null,
            'activations_used' => $license->activations_count ?? 0,
            'activations_limit' => (int) $license->max_activations,
            'features' => $this->format_features($tier_features),
            // Least-disclosure: this response is returned to ANY key holder over
            // /license/status and /license/validate. We must NOT leak other
            // customer sites' identifying fields (site_url / site_name) to the
            // caller. Expose only aggregate, non-identifying seat metadata per
            // active activation (activation date + active flag); the seat counts
            // the SDK/admin rely on remain in activations_used/activations_limit.
            'activated_sites' => array_values(array_map(function ($activation) {
                return [
                    'activated_at' => $activation->activated_at,
                    'is_active' => (bool) $activation->is_active,
                ];
            }, array_filter($license->activations ?? [], fn($a) => $a->is_active))),
        ];
    }

    /**
     * Format features as boolean array
     */
    private function format_features(array $features): array {
        $all_features = [
            'utm' => false,
            'links' => false,
            'contacts' => false,
            'dashboard' => false,
            'popups' => false,
            'analytics' => false,
            'export' => false,
            'monitor' => false,
            'white_label' => false,
            'priority_support' => false,
        ];

        foreach ($features as $feature) {
            if (isset($all_features[$feature])) {
                $all_features[$feature] = true;
            }
        }

        return $all_features;
    }

    /**
     * Get last error code
     */
    public function get_last_error(): string {
        return $this->last_error;
    }

    /**
     * Get last error message
     */
    public function get_last_error_message(): string {
        return $this->last_error_message;
    }

    /**
     * Validate license key format
     */
    public static function is_valid_format(string $key): bool {
        // Format: XXXX-XXXX-XXXX-XXXX (4 groups of 4 alphanumeric characters)
        return (bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/i', $key);
    }

    /**
     * Sanitize license key
     */
    public static function sanitize_key(string $key): string {
        // Remove whitespace and convert to uppercase
        $key = strtoupper(trim($key));

        // Remove any characters that aren't alphanumeric or hyphens
        $key = preg_replace('/[^A-Z0-9-]/', '', $key);

        return $key;
    }
}
