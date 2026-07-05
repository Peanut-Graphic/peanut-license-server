<?php
/**
 * REST API Endpoints Class
 *
 * Registers and handles all REST API routes.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_API_Endpoints {

    /**
     * API namespace
     */
    private const NAMESPACE = 'peanut-api/v1';

    /**
     * Register all routes
     */
    public function register_routes(): void {
        // License validation and activation
        register_rest_route(self::NAMESPACE, '/license/validate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'validate_license'],
            'permission_callback' => [Peanut_API_Security::class, 'permission_public_license'],
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'site_url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'site_name' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'plugin_version' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // License deactivation
        register_rest_route(self::NAMESPACE, '/license/deactivate', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'deactivate_license'],
            'permission_callback' => [Peanut_API_Security::class, 'permission_public_license'],
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'site_url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ]);

        // License status check (no activation)
        register_rest_route(self::NAMESPACE, '/license/status', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_license_status'],
            'permission_callback' => [Peanut_API_Security::class, 'permission_public_license'],
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Plugin update check
        register_rest_route(self::NAMESPACE, '/updates/check', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'check_update'],
            'permission_callback' => [Peanut_API_Security::class, 'permission_public_readonly'],
            'args' => [
                'plugin' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => 'peanut-suite',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'version' => [
                    'required' => false,
                    'type' => 'string',
                    'default' => '0.0.0',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'license' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'site_url' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ]);

        // Plugin info (for WordPress plugins_api)
        register_rest_route(self::NAMESPACE, '/updates/info', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_plugin_info'],
            'permission_callback' => [Peanut_API_Security::class, 'permission_public_readonly'],
            'args' => [
                'license' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Plugin download (DEPRECATED self-hosted path).
        // Superseded by GitHub-release delivery: update checks now hand clients a
        // canonical GitHub Releases URL (get_download_url()), so WordPress fetches
        // the package straight from GitHub and never hits this route. Retained
        // only as a fallback for the legacy self-hosted ?peanut_download / uploads
        // ZIPs; safe to delete once all installs are off that path.
        // Egress is DEFAULT-DENY inside download_plugin(): a caller must present
        // either a valid signed download token or a resolving valid+active
        // license. The public permission_callback only gates IP-block/rate
        // limiting; the byte-serving authorization happens in the handler.
        register_rest_route(self::NAMESPACE, '/updates/download', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'download_plugin'],
            'permission_callback' => [Peanut_API_Security::class, 'permission_public_readonly'],
            'args' => [
                'license' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'token' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Per-product update check (absorbs the per-product update mu-plugins).
        //
        // Serves the exact route the mu-plugins answer, e.g.
        //   /updates/peanut-connect/3.20.0
        //   /updates/peanut-suite/4.2.2
        // so the peanut-connect client updater (which calls
        // /updates/<slug>/<version>) — and every other per-product updater — can
        // be served from this plugin, letting the mu-plugins be deleted. The
        // response is IDENTICAL to /updates/check (same Peanut_Update_Server
        // methods), so delivery is the already-unified GitHub-release URL.
        //
        // COLLISION SAFETY: the {plugin} capture uses a negative lookahead that
        // refuses the reserved words check|info|download, so this generic route
        // can never shadow the literal /updates/check, /updates/info or
        // /updates/download routes registered above. (Those literals also lack
        // the trailing "/<version>" segment this pattern requires, so they would
        // not match regardless — the lookahead is belt-and-suspenders.) Unknown
        // slugs are rejected 400 in the handler via is_valid_product().
        register_rest_route(self::NAMESPACE, '/updates/(?P<plugin>(?!check|info|download)[a-z0-9-]+)/(?P<current_version>[0-9.]+)?', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'check_product_update'],
            'permission_callback' => [Peanut_API_Security::class, 'permission_public_readonly'],
            'args' => [
                'license' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);

        // Health check
        register_rest_route(self::NAMESPACE, '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'health_check'],
            'permission_callback' => [Peanut_API_Security::class, 'permission_public_readonly'],
        ]);

        // Site health report (client sites report their status)
        register_rest_route(self::NAMESPACE, '/site/health', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'report_site_health'],
            'permission_callback' => [Peanut_API_Security::class, 'permission_public_license'],
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'site_url' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw',
                ],
                'plugin_version' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'wp_version' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'php_version' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'is_multisite' => [
                    'required' => false,
                    'type' => 'boolean',
                ],
                'active_plugins' => [
                    'required' => false,
                    'type' => 'integer',
                ],
                'health_status' => [
                    'required' => false,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'default' => 'healthy',
                ],
                'errors' => [
                    'required' => false,
                    'type' => 'array',
                ],
            ],
        ]);

        // Get all activations for a license (for admin dashboard view).
        // Requires WordPress admin authentication since it exposes every
        // activated site (domain, IP, WP/PHP/plugin versions, health) — data
        // that must NOT be readable by any holder of a single valid key. Mirrors
        // the peanut-admin/v1 routes' admin_permission_check.
        register_rest_route(self::NAMESPACE, '/license/activations', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_license_activations'],
            'permission_callback' => [$this, 'admin_permission_check'],
            'args' => [
                'license_key' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Validate and activate license
     */
    public function validate_license(WP_REST_Request $request): WP_REST_Response {
        // Check rate limit
        $rate_limited = Peanut_Rate_Limiter::check('license_validate');
        if ($rate_limited) {
            return $rate_limited;
        }

        // Record request
        Peanut_Rate_Limiter::record_request('license_validate');

        $license_key = Peanut_License_Validator::sanitize_key($request->get_param('license_key'));
        $site_url = $request->get_param('site_url');

        if (!Peanut_License_Validator::is_valid_format($license_key)) {
            // Log failed attempt
            Peanut_Validation_Logger::log_failure(
                $license_key,
                $site_url,
                'invalid_format',
                'Invalid license key format',
                'validate'
            );

            $response = new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_format',
                'message' => __('Invalid license key format.', 'peanut-license-server'),
            ], 400);

            return Peanut_Rate_Limiter::add_headers($response, 'license_validate');
        }

        $validator = new Peanut_License_Validator();
        $result = $validator->validate_and_activate($license_key, [
            'site_url' => $site_url,
            'site_name' => $request->get_param('site_name'),
            'plugin_version' => $request->get_param('plugin_version'),
        ]);

        // Log the attempt
        if ($result['success']) {
            Peanut_Validation_Logger::log_success($license_key, $site_url, 'validate');
        } else {
            Peanut_Validation_Logger::log_failure(
                $license_key,
                $site_url,
                $result['error'] ?? 'unknown',
                $result['message'] ?? 'Unknown error',
                'validate'
            );
        }

        $status = $result['success'] ? 200 : 400;
        $response = new WP_REST_Response($result, $status);

        return Peanut_Rate_Limiter::add_headers($response, 'license_validate');
    }

    /**
     * Deactivate license from site
     */
    public function deactivate_license(WP_REST_Request $request): WP_REST_Response {
        // Check rate limit
        $rate_limited = Peanut_Rate_Limiter::check('license_validate');
        if ($rate_limited) {
            return $rate_limited;
        }

        Peanut_Rate_Limiter::record_request('license_validate');

        $license_key = Peanut_License_Validator::sanitize_key($request->get_param('license_key'));
        $site_url = $request->get_param('site_url');

        if (!Peanut_License_Validator::is_valid_format($license_key)) {
            Peanut_Validation_Logger::log_failure(
                $license_key,
                $site_url,
                'invalid_format',
                'Invalid license key format',
                'deactivate'
            );

            $response = new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_format',
                'message' => __('Invalid license key format.', 'peanut-license-server'),
            ], 400);

            return Peanut_Rate_Limiter::add_headers($response, 'license_validate');
        }

        $validator = new Peanut_License_Validator();
        $result = $validator->deactivate($license_key, $site_url);

        // Log the attempt
        if ($result['success']) {
            Peanut_Validation_Logger::log_success($license_key, $site_url, 'deactivate');
        } else {
            Peanut_Validation_Logger::log_failure(
                $license_key,
                $site_url,
                $result['error'] ?? 'unknown',
                $result['message'] ?? 'Unknown error',
                'deactivate'
            );
        }

        $status = $result['success'] ? 200 : 400;
        $response = new WP_REST_Response($result, $status);

        return Peanut_Rate_Limiter::add_headers($response, 'license_validate');
    }

    /**
     * Get license status without activation
     */
    public function get_license_status(WP_REST_Request $request): WP_REST_Response {
        // Check rate limit
        $rate_limited = Peanut_Rate_Limiter::check('license_status');
        if ($rate_limited) {
            return $rate_limited;
        }

        Peanut_Rate_Limiter::record_request('license_status');

        $license_key = Peanut_License_Validator::sanitize_key($request->get_param('license_key'));

        if (!Peanut_License_Validator::is_valid_format($license_key)) {
            Peanut_Validation_Logger::log_failure(
                $license_key,
                '',
                'invalid_format',
                'Invalid license key format',
                'status_check'
            );

            $response = new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_format',
                'message' => __('Invalid license key format.', 'peanut-license-server'),
            ], 400);

            return Peanut_Rate_Limiter::add_headers($response, 'license_status');
        }

        $validator = new Peanut_License_Validator();
        $result = $validator->validate_only($license_key);

        $status = $result['success'] ? 200 : 400;
        $response = new WP_REST_Response($result, $status);

        return Peanut_Rate_Limiter::add_headers($response, 'license_status');
    }

    /**
     * Check for plugin updates
     */
    public function check_update(WP_REST_Request $request): WP_REST_Response {
        // Check rate limit
        $rate_limited = Peanut_Rate_Limiter::check('update_check');
        if ($rate_limited) {
            return $rate_limited;
        }

        Peanut_Rate_Limiter::record_request('update_check');

        $plugin = $request->get_param('plugin');

        // Validate plugin slug
        if (!Peanut_Update_Server::is_valid_product($plugin)) {
            $response = new WP_REST_Response([
                'error' => 'invalid_plugin',
                'message' => __('Unknown plugin.', 'peanut-license-server'),
                'valid_plugins' => array_keys(Peanut_Update_Server::get_all_products()),
            ], 400);

            return Peanut_Rate_Limiter::add_headers($response, 'update_check');
        }

        $current_version = $request->get_param('version');
        $license_key = $request->get_param('license');

        $update_server = new Peanut_Update_Server($plugin);
        $result = $update_server->check_update($current_version, $license_key);

        $response = new WP_REST_Response($result, 200);

        return Peanut_Rate_Limiter::add_headers($response, 'update_check');
    }

    /**
     * Per-product update check served on the route the mu-plugins answer:
     *   /updates/<plugin>/<current_version>
     *
     * Reads {plugin} and {current_version} from the URL path (the client
     * updaters — notably peanut-connect — call this shape instead of the
     * ?plugin=&version= query form). Returns the SAME payload as check_update()
     * by delegating to the SAME Peanut_Update_Server methods, so the download
     * URL is the already-unified canonical GitHub-release package. This is what
     * lets the per-product update mu-plugins be retired.
     */
    public function check_product_update(WP_REST_Request $request): WP_REST_Response {
        // Check rate limit (shared bucket with /updates/check).
        $rate_limited = Peanut_Rate_Limiter::check('update_check');
        if ($rate_limited) {
            return $rate_limited;
        }

        Peanut_Rate_Limiter::record_request('update_check');

        $plugin = $request->get_param('plugin');

        // Validate plugin slug — unknown products are rejected (400).
        if (!is_string($plugin) || !Peanut_Update_Server::is_valid_product($plugin)) {
            $response = new WP_REST_Response([
                'error' => 'invalid_plugin',
                'message' => __('Unknown plugin.', 'peanut-license-server'),
                'valid_plugins' => array_keys(Peanut_Update_Server::get_all_products()),
            ], 400);

            return Peanut_Rate_Limiter::add_headers($response, 'update_check');
        }

        // {current_version} is an optional path segment; default to 0.0.0 so a
        // bare /updates/<plugin>/ still resolves (mirrors the mu-plugin default).
        $current_version = $request->get_param('current_version');
        if (!is_string($current_version) || $current_version === '') {
            $current_version = '0.0.0';
        }

        $license_key = $request->get_param('license');

        $update_server = new Peanut_Update_Server($plugin);
        $result = $update_server->check_update($current_version, $license_key);

        $response = new WP_REST_Response($result, 200);

        return Peanut_Rate_Limiter::add_headers($response, 'update_check');
    }

    /**
     * Get full plugin info (for WordPress plugins_api)
     */
    public function get_plugin_info(WP_REST_Request $request): WP_REST_Response {
        $plugin = $request->get_param('plugin') ?? 'peanut-suite';
        $license_key = $request->get_param('license');

        if (!Peanut_Update_Server::is_valid_product($plugin)) {
            return new WP_REST_Response([
                'error' => 'invalid_plugin',
                'message' => __('Unknown plugin.', 'peanut-license-server'),
            ], 400);
        }

        $update_server = new Peanut_Update_Server($plugin);
        $info = $update_server->get_plugin_info($license_key);

        return new WP_REST_Response($info, 200);
    }

    /**
     * Download plugin ZIP
     */
    public function download_plugin(WP_REST_Request $request): void {
        // Check rate limit for downloads
        if (Peanut_Rate_Limiter::is_rate_limited('download')) {
            status_header(429);
            wp_die(__('Too many download requests. Please try again later.', 'peanut-license-server'), 429);
        }

        Peanut_Rate_Limiter::record_request('download');

        $plugin = $request->get_param('plugin') ?? 'peanut-suite';
        $license_key = $request->get_param('license');
        $token = $request->get_param('token');

        if (!Peanut_Update_Server::is_valid_product($plugin)) {
            wp_die(__('Unknown plugin.', 'peanut-license-server'), 400);
        }

        // DEFAULT-DENY: this public REST route must never stream the full plugin
        // ZIP to an unauthenticated caller. Require EITHER a valid signed
        // download token (same primitive the ?peanut_download handler uses) OR a
        // resolving valid+active license before serving any bytes.
        $authorized = false;

        if (is_string($token) && $token !== ''
            && peanut_verify_download_token($plugin, $token, is_string($license_key) ? $license_key : '')) {
            $authorized = true;
        } elseif (is_string($license_key) && $license_key !== '') {
            $license = Peanut_License_Manager::get_by_key($license_key);
            $authorized = $license && Peanut_License_Manager::is_valid($license);
        }

        if (!$authorized) {
            status_header(403);
            wp_die(
                __('Unauthorized. A valid download token or active license is required.', 'peanut-license-server'),
                403
            );
        }

        $update_server = new Peanut_Update_Server($plugin);
        $update_server->serve_download($license_key, true);
    }

    /**
     * Health check endpoint
     */
    public function health_check(): WP_REST_Response {
        return new WP_REST_Response([
            'status' => 'ok',
            'version' => PEANUT_LICENSE_SERVER_VERSION,
            'plugin_version' => get_option('peanut_license_server_plugin_version', '1.0.0'),
            'timestamp' => current_time('c'),
        ], 200);
    }

    /**
     * Report site health from a client installation.
     */
    public function report_site_health(WP_REST_Request $request): WP_REST_Response {
        // SECURITY: Check rate limit for health reports
        $rate_limited = Peanut_Rate_Limiter::check('site_health');
        if ($rate_limited) {
            return $rate_limited;
        }
        Peanut_Rate_Limiter::record_request('site_health');

        global $wpdb;

        $license_key = $request->get_param('license_key');
        $site_url = $request->get_param('site_url');

        // Validate the license key format first
        if (!Peanut_License_Validator::is_valid_format($license_key)) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_format',
                'message' => __('Invalid license key format.', 'peanut-license-server'),
            ], 400);
        }

        // Get the license from the database
        $license = Peanut_License_Manager::get_by_key($license_key);

        if (!$license) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_license',
                'message' => __('Invalid license key.', 'peanut-license-server'),
            ], 400);
        }

        // Find the activation record.
        $site_hash = md5($site_url);
        $activation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}peanut_activations
             WHERE license_id = %d AND site_hash = %s",
            $license->id,
            $site_hash
        ));

        if (!$activation) {
            return new WP_REST_Response([
                'success' => false,
                'error' => 'not_activated',
                'message' => __('This site is not activated for this license.', 'peanut-license-server'),
            ], 400);
        }

        // Update health data.
        $health_data = [
            'plugin_version' => $request->get_param('plugin_version') ?: '',
            'wp_version' => $request->get_param('wp_version') ?: '',
            'php_version' => $request->get_param('php_version') ?: '',
            'is_multisite' => $request->get_param('is_multisite') ? 1 : 0,
            'active_plugins' => absint($request->get_param('active_plugins')),
            'health_status' => $request->get_param('health_status') ?: 'healthy',
            'last_checked' => current_time('mysql'),
        ];

        // Store errors if any.
        $errors = $request->get_param('errors');
        if (!empty($errors) && is_array($errors)) {
            $health_data['health_errors'] = wp_json_encode(array_slice($errors, 0, 10));
            if (!empty($errors)) {
                $health_data['health_status'] = 'warning';
            }
        }

        $wpdb->update(
            $wpdb->prefix . 'peanut_activations',
            $health_data,
            ['id' => $activation->id],
            ['%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s'],
            ['%d']
        );

        return new WP_REST_Response([
            'success' => true,
            'message' => __('Health status updated.', 'peanut-license-server'),
            'next_report' => current_time('timestamp') + DAY_IN_SECONDS,
        ], 200);
    }

    /**
     * Check admin permissions for sensitive endpoints
     *
     * @param WP_REST_Request $request The request object
     * @return bool|WP_Error True if allowed, error otherwise
     */
    public function admin_permission_check(WP_REST_Request $request) {
        if (!is_user_logged_in()) {
            return new WP_Error(
                'rest_not_logged_in',
                __('You must be logged in to access this endpoint.', 'peanut-license-server'),
                ['status' => 401]
            );
        }

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
     * Get all activations for a license.
     */
    public function get_license_activations(WP_REST_Request $request): WP_REST_Response {
        // SECURITY: Check rate limit for activation lookups
        $rate_limited = Peanut_Rate_Limiter::check('license_activations');
        if ($rate_limited) {
            return $rate_limited;
        }
        Peanut_Rate_Limiter::record_request('license_activations');

        global $wpdb;

        $license_key = $request->get_param('license_key');

        // Validate the license key format first
        if (!Peanut_License_Validator::is_valid_format($license_key)) {
            // Log potential enumeration attempt
            Peanut_Validation_Logger::log_failure(
                $license_key,
                '',
                'invalid_format',
                'Invalid license key format in activations lookup',
                'activations_lookup'
            );

            $response = new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_format',
                'message' => __('Invalid license key format.', 'peanut-license-server'),
            ], 400);

            return Peanut_Rate_Limiter::add_headers($response, 'license_activations');
        }

        // Get the license from the database
        $license = Peanut_License_Manager::get_by_key($license_key);

        if (!$license) {
            // Log invalid license lookup (potential enumeration)
            Peanut_Validation_Logger::log_failure(
                $license_key,
                '',
                'invalid_license',
                'Invalid license key in activations lookup',
                'activations_lookup'
            );

            $response = new WP_REST_Response([
                'success' => false,
                'error' => 'invalid_license',
                'message' => __('Invalid license key.', 'peanut-license-server'),
            ], 400);

            return Peanut_Rate_Limiter::add_headers($response, 'license_activations');
        }

        // Get all activations with a single query including active count
        $activations = $wpdb->get_results($wpdb->prepare(
            "SELECT site_url, site_name, plugin_version, wp_version, php_version,
                    health_status, activated_at, last_checked, is_active
             FROM {$wpdb->prefix}peanut_activations
             WHERE license_id = %d
             ORDER BY activated_at DESC",
            $license->id
        ));

        // Count active activations in the query result
        $active_count = 0;
        foreach ($activations as $activation) {
            if ($activation->is_active) {
                $active_count++;
            }
        }

        $response = new WP_REST_Response([
            'success' => true,
            'license' => [
                'status' => $license->status,
                'tier' => $license->tier,
                'max_activations' => $license->max_activations,
                'expires_at' => $license->expires_at,
            ],
            'activations' => [
                'count' => $active_count,
                'limit' => $license->max_activations,
                'remaining' => max(0, $license->max_activations - $active_count),
                'sites' => $activations,
            ],
        ], 200);

        return Peanut_Rate_Limiter::add_headers($response, 'license_activations');
    }
}
