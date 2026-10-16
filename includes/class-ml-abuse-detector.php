<?php
/**
 * ML-Powered Abuse Detection
 *
 * Integrates with the Peanut ML microservice to score license
 * validation requests for abuse likelihood. Works alongside
 * the existing Peanut_API_Security and Peanut_Rate_Limiter
 * systems, adding learned behavioral analysis on top of
 * the existing rule-based thresholds.
 *
 * Integration: Called from Peanut_API_Security::permission_public_license()
 * before processing each license validation request.
 *
 * @package Peanut_License_Server
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Peanut_ML_Abuse_Detector {

    /**
     * ML service base URL.
     * Configure via Settings > License Server > ML Service.
     */
    private static $service_url = null;

    /**
     * API key for ML service authentication.
     */
    private static $api_key = null;

    /**
     * Cache group for ML scores.
     */
    const CACHE_GROUP = 'peanut_ml_abuse';

    /**
     * Score threshold for blocking (0.0 - 1.0).
     */
    const BLOCK_THRESHOLD = 0.8;

    /**
     * Score threshold for challenge/rate-limit tightening.
     */
    const CHALLENGE_THRESHOLD = 0.5;

    /**
     * Cache TTL for scores (seconds).
     */
    const SCORE_CACHE_TTL = 300; // 5 minutes

    /**
     * Initialize settings from WordPress options.
     */
    private static function init() {
        if ( self::$service_url === null ) {
            $settings = get_option( 'peanut_ml_settings', array() );
            self::$service_url = isset( $settings['service_url'] )
                ? trailingslashit( $settings['service_url'] )
                : 'http://127.0.0.1:8100/';
            self::$api_key = isset( $settings['api_key'] ) ? $settings['api_key'] : '';
        }
    }

    /**
     * Check if the ML service is configured and reachable.
     *
     * @return bool
     */
    public static function is_available(): bool {
        self::init();

        $cached = get_transient( 'peanut_ml_available' );
        if ( $cached !== false ) {
            return $cached === 'yes';
        }

        $response = wp_remote_get( self::$service_url . 'health', array(
            'timeout' => 3,
            'sslverify' => false,
        ) );

        $available = ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
        set_transient( 'peanut_ml_available', $available ? 'yes' : 'no', 60 );

        return $available;
    }

    /**
     * Score a license validation request for abuse.
     *
     * Call this before processing the validation. If the score
     * exceeds the block threshold, return a 403 instead of
     * processing the request.
     *
     * @param string      $license_key_hash SHA256 hash of the license key.
     * @param string      $ip_address       Client IP address.
     * @param string|null $user_agent        Client user agent.
     * @param string|null $site_url          Site URL from the request.
     * @param string      $action            Action type (validate, deactivate, status_check).
     * @return array {
     *     @type float  $abuse_score         Score from 0.0 (normal) to 1.0 (abusive).
     *     @type bool   $should_block        Whether to block this request.
     *     @type array  $risk_factors        Human-readable risk factors.
     *     @type string $recommended_action  "allow", "challenge", or "block".
     * }
     */
    public static function score_request(
        string $license_key_hash,
        string $ip_address,
        ?string $user_agent = null,
        ?string $site_url = null,
        string $action = 'validate'
    ): array {
        self::init();

        // Check cache first (avoid hammering the ML service)
        $cache_key = 'ml_score_' . md5( $license_key_hash . $ip_address );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        // If ML service is not available, return neutral score
        if ( ! self::is_available() ) {
            return self::neutral_response();
        }

        $response = wp_remote_post( self::$service_url . 'license/score', array(
            'timeout' => 5,
            'sslverify' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-ML-API-Key' => self::$api_key,
            ),
            'body' => wp_json_encode( array(
                'license_key_hash' => $license_key_hash,
                'ip_address'       => $ip_address,
                'user_agent'       => $user_agent,
                'site_url'         => $site_url,
                'action'           => $action,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            Peanut_Logger::warning( 'ML service request failed: ' . $response->get_error_message() );
            return self::neutral_response();
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            Peanut_Logger::warning( 'ML service returned HTTP ' . $code );
            return self::neutral_response();
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! $body || ! isset( $body['abuse_score'] ) ) {
            return self::neutral_response();
        }

        $result = array(
            'abuse_score'        => (float) $body['abuse_score'],
            'should_block'       => (bool) $body['should_block'],
            'risk_factors'       => $body['risk_factors'] ?? array(),
            'recommended_action' => $body['recommended_action'] ?? 'allow',
            'model_version'      => $body['model_version'] ?? 'unknown',
        );

        // Cache the result
        set_transient( $cache_key, $result, self::SCORE_CACHE_TTL );

        // Log high-risk scores for review
        if ( $result['abuse_score'] >= self::CHALLENGE_THRESHOLD ) {
            self::log_high_risk_score( $license_key_hash, $ip_address, $result );
        }

        return $result;
    }

    /**
     * Apply ML-based enforcement decisions.
     *
     * Call from Peanut_API_Security::permission_public_license() to
     * integrate ML scoring into the existing security pipeline.
     *
     * @param string      $license_key  The raw license key.
     * @param string      $ip_address   Client IP.
     * @param string|null $user_agent   Client user agent.
     * @param string|null $site_url     Requesting site URL.
     * @param string      $action       Action type.
     * @return true|WP_Error True if allowed, WP_Error if blocked.
     */
    public static function enforce(
        string $license_key,
        string $ip_address,
        ?string $user_agent = null,
        ?string $site_url = null,
        string $action = 'validate'
    ) {
        $license_key_hash = hash( 'sha256', $license_key );
        $score = self::score_request( $license_key_hash, $ip_address, $user_agent, $site_url, $action );

        if ( $score['should_block'] ) {
            // Block and log
            Peanut_Audit_Trail::log( 'ml_abuse_blocked', array(
                'license_key_hash' => $license_key_hash,
                'ip_address'       => $ip_address,
                'abuse_score'      => $score['abuse_score'],
                'risk_factors'     => $score['risk_factors'],
            ) );

            // Also use existing IP blocking system for consistency
            Peanut_API_Security::block_ip( $ip_address );

            return new WP_Error(
                'ml_abuse_detected',
                'Request blocked due to suspicious activity patterns.',
                array( 'status' => 403 )
            );
        }

        if ( $score['recommended_action'] === 'challenge' ) {
            // Tighten rate limits for suspicious traffic
            // Reduce from 10/min to 3/min for this IP
            $rate_key = 'peanut_rate_ml_throttle_' . md5( $ip_address );
            $current = (int) get_transient( $rate_key );
            if ( $current >= 3 ) {
                return new WP_Error(
                    'ml_rate_limited',
                    'Too many requests. Please try again later.',
                    array( 'status' => 429 )
                );
            }
            set_transient( $rate_key, $current + 1, 60 );
        }

        return true;
    }

    /**
     * Trigger model retraining.
     * Call this from a weekly WP-Cron event.
     *
     * @return array Training result from ML service.
     */
    public static function train_model(): array {
        self::init();

        $response = wp_remote_post( self::$service_url . 'license/train', array(
            'timeout' => 120, // Training can take time
            'sslverify' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-ML-API-Key' => self::$api_key,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            Peanut_Logger::error( 'ML model training failed: ' . $response->get_error_message() );
            return array( 'status' => 'error', 'message' => $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        Peanut_Logger::info( 'ML model training completed: ' . wp_json_encode( $body ) );

        return $body ?? array( 'status' => 'error', 'message' => 'Invalid response' );
    }

    /**
     * Get abuse detection statistics.
     *
     * @return array Stats from the ML service.
     */
    public static function get_stats(): array {
        self::init();

        $response = wp_remote_get( self::$service_url . 'license/stats', array(
            'timeout' => 10,
            'sslverify' => false,
            'headers' => array(
                'X-ML-API-Key' => self::$api_key,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array( 'error' => $response->get_error_message() );
        }

        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? array();
    }

    /**
     * Return a neutral response when ML service is unavailable.
     * Never block traffic due to ML service outages.
     *
     * @return array
     */
    private static function neutral_response(): array {
        return array(
            'abuse_score'        => 0.0,
            'should_block'       => false,
            'risk_factors'       => array(),
            'recommended_action' => 'allow',
            'model_version'      => 'fallback',
        );
    }

    /**
     * Log a high-risk score for admin review.
     *
     * @param string $license_key_hash
     * @param string $ip_address
     * @param array  $score
     */
    private static function log_high_risk_score( string $license_key_hash, string $ip_address, array $score ): void {
        Peanut_Audit_Trail::log( 'ml_high_risk_detected', array(
            'license_key_hash' => $license_key_hash,
            'ip_address'       => $ip_address,
            'abuse_score'      => $score['abuse_score'],
            'risk_factors'     => $score['risk_factors'],
            'action'           => $score['recommended_action'],
            'model_version'    => $score['model_version'],
        ) );
    }

    /**
     * Register the weekly training cron event.
     * Call this from the plugin activation hook.
     */
    public static function schedule_training(): void {
        if ( ! wp_next_scheduled( 'peanut_ml_train_abuse_model' ) ) {
            wp_schedule_event( time(), 'weekly', 'peanut_ml_train_abuse_model' );
        }
    }

    /**
     * Unschedule all training cron events.
     * Call this from the plugin deactivation hook.
     */
    public static function unschedule_training(): void {
        wp_clear_scheduled_hook( 'peanut_ml_train_abuse_model' );
    }
}
