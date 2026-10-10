<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * Peanut License Client SDK
 *
 * A drop-in class for WordPress plugins to integrate with the Peanut License Server.
 * Include this file in your plugin to enable license validation and auto-updates.
 *
 * @package Peanut_License_Client
 * @version 1.0.0
 *
 * USAGE:
 *
 * 1. Include this file in your plugin:
 *    require_once plugin_dir_path(__FILE__) . 'includes/class-peanut-license-client.php';
 *
 * 2. Initialize the client:
 *    $license_client = new Peanut_License_Client([
 *        'api_url' => 'https://yoursite.com/wp-json/peanut-api/v1',
 *        'plugin_slug' => 'your-plugin',
 *        'plugin_file' => __FILE__,
 *        'plugin_name' => 'Your Plugin Name',
 *        'version' => '1.0.0',
 *        'license_option' => 'your_plugin_license_key',
 *        'status_option' => 'your_plugin_license_status',
 *    ]);
 *
 * 3. Add settings page integration:
 *    $license_client->render_license_field(); // In your settings page
 *
 * 4. Check features in your code:
 *    if ($license_client->has_feature('analytics')) {
 *        // Show analytics feature
 *    }
 */

if (!class_exists('Peanut_License_Client')) {

    class Peanut_License_Client {

        /**
         * Client version
         */
        public const VERSION = '1.0.1';

        /**
         * Configuration
         */
        private array $config;

        /**
         * Cached license data
         */
        private ?array $license_data = null;

        /**
         * Constructor
         */
        public function __construct(array $config) {
            $defaults = [
                'api_url' => '',
                'plugin_slug' => '',
                'plugin_file' => '',
                'plugin_name' => '',
                'version' => '1.0.0',
                'license_option' => '',
                'status_option' => '',
                'cache_duration' => 12 * HOUR_IN_SECONDS,
                'auto_updates' => true,
            ];

            $this->config = wp_parse_args($config, $defaults);

            $this->init_hooks();
        }

        /**
         * Initialize hooks
         */
        private function init_hooks(): void {
            // Auto-updates
            if ($this->config['auto_updates']) {
                add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
                add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
                add_action('in_plugin_update_message-' . plugin_basename($this->config['plugin_file']), [$this, 'update_message'], 10, 2);
            }

            // AJAX handlers for license management
            add_action('wp_ajax_peanut_activate_license_' . $this->config['plugin_slug'], [$this, 'ajax_activate_license']);
            add_action('wp_ajax_peanut_deactivate_license_' . $this->config['plugin_slug'], [$this, 'ajax_deactivate_license']);
            add_action('wp_ajax_peanut_check_license_' . $this->config['plugin_slug'], [$this, 'ajax_check_license']);

            // Admin notices
            add_action('admin_notices', [$this, 'admin_notices']);
        }

        /**
         * Get license key
         */
        public function get_license_key(): string {
            return get_option($this->config['license_option'], '');
        }

        /**
         * Set license key
         */
        public function set_license_key(string $key): bool {
            return update_option($this->config['license_option'], sanitize_text_field($key));
        }

        /**
         * Get cached license data
         */
        public function get_license_data(): ?array {
            if ($this->license_data !== null) {
                return $this->license_data;
            }

            $cached = get_transient($this->config['status_option'] . '_data');

            if ($cached !== false) {
                $this->license_data = $cached;
                return $cached;
            }

            return null;
        }

        /**
         * Activate license
         */
        public function activate(string $license_key = ''): array {
            $license_key = $license_key ?: $this->get_license_key();

            if (empty($license_key)) {
                return [
                    'success' => false,
                    'error' => 'missing_key',
                    'message' => __('Please enter a license key.', 'peanut-license-client'),
                ];
            }

            $response = $this->api_request('license/validate', [
                'license_key' => $license_key,
                'site_url' => home_url(),
                'site_name' => get_bloginfo('name'),
                'plugin_version' => $this->config['version'],
            ], 'POST');

            if ($response['success']) {
                $this->set_license_key($license_key);
                $this->cache_license_data($response['license']);
                update_option($this->config['status_option'], 'active');
            } else {
                update_option($this->config['status_option'], 'invalid');
            }

            return $response;
        }

        /**
         * Deactivate license
         */
        public function deactivate(): array {
            $license_key = $this->get_license_key();

            if (empty($license_key)) {
                return [
                    'success' => false,
                    'error' => 'missing_key',
                    'message' => __('No license key to deactivate.', 'peanut-license-client'),
                ];
            }

            $response = $this->api_request('license/deactivate', [
                'license_key' => $license_key,
                'site_url' => home_url(),
            ], 'POST');

            if ($response['success']) {
                $this->clear_license_cache();
                update_option($this->config['status_option'], 'deactivated');
            }

            return $response;
        }

        /**
         * Check license status
         */
        public function check_status(bool $force = false): array {
            $license_key = $this->get_license_key();

            if (empty($license_key)) {
                return [
                    'success' => false,
                    'status' => 'inactive',
                    'message' => __('No license key configured.', 'peanut-license-client'),
                ];
            }

            // Use cached data if available and not forcing refresh
            if (!$force) {
                $cached = $this->get_license_data();
                if ($cached) {
                    return [
                        'success' => true,
                        'license' => $cached,
                        'cached' => true,
                    ];
                }
            }

            $response = $this->api_request('license/status', [
                'license_key' => $license_key,
            ], 'GET');

            if ($response['success']) {
                $this->cache_license_data($response['license']);
                update_option($this->config['status_option'], $response['license']['status'] ?? 'active');
            }

            return $response;
        }

        /**
         * Check if a feature is available
         */
        public function has_feature(string $feature): bool {
            $license_data = $this->get_license_data();

            if (!$license_data) {
                // Check status to populate cache
                $result = $this->check_status();
                if (!$result['success']) {
                    return false;
                }
                $license_data = $result['license'] ?? null;
            }

            if (!$license_data) {
                return false;
            }

            $features = $license_data['features'] ?? [];

            // Handle both array and object formats
            if (is_array($features) && isset($features[$feature])) {
                return (bool) $features[$feature];
            }

            return in_array($feature, $features, true);
        }

        /**
         * Get current tier
         */
        public function get_tier(): string {
            $license_data = $this->get_license_data();
            return $license_data['tier'] ?? 'free';
        }

        /**
         * Check if license is active
         */
        public function is_active(): bool {
            $status = get_option($this->config['status_option'], '');
            return $status === 'active';
        }

        /**
         * Check for plugin updates
         */
        public function check_for_updates($transient) {
            if (empty($transient->checked)) {
                return $transient;
            }

            $response = $this->api_request('updates/check', [
                'plugin' => $this->config['plugin_slug'],
                'version' => $this->config['version'],
                'license' => $this->get_license_key(),
                'site_url' => home_url(),
            ], 'GET');

            if (!empty($response['update_available']) && !empty($response['plugin_info'])) {
                $plugin_file = plugin_basename($this->config['plugin_file']);
                $info = $response['plugin_info'];

                $transient->response[$plugin_file] = (object) [
                    'id' => $this->config['plugin_slug'],
                    'slug' => $info['slug'],
                    'plugin' => $plugin_file,
                    'new_version' => $info['new_version'],
                    'url' => $info['homepage'],
                    'package' => $info['download_url'],
                    'icons' => $info['icons'] ?? [],
                    'banners' => $info['banners'] ?? [],
                    'tested' => $info['tested'] ?? '',
                    'requires_php' => $info['requires_php'] ?? '',
                    'compatibility' => new stdClass(),
                ];
            }

            return $transient;
        }

        /**
         * Plugin info for WordPress plugins_api
         */
        public function plugin_info($result, $action, $args) {
            if ($action !== 'plugin_information') {
                return $result;
            }

            if ($args->slug !== $this->config['plugin_slug']) {
                return $result;
            }

            $response = $this->api_request('updates/info', [
                'plugin' => $this->config['plugin_slug'],
                'license' => $this->get_license_key(),
            ], 'GET');

            if (empty($response) || isset($response['error'])) {
                return $result;
            }

            return (object) [
                'name' => $response['name'],
                'slug' => $response['slug'],
                'version' => $response['version'],
                'author' => $response['author'],
                'homepage' => $response['homepage'],
                'download_link' => $response['download_url'],
                'trunk' => $response['download_url'],
                'requires' => $response['requires'],
                'tested' => $response['tested'],
                'requires_php' => $response['requires_php'],
                'last_updated' => $response['last_updated'],
                'sections' => $response['sections'],
                'banners' => $response['banners'] ?? [],
                'icons' => $response['icons'] ?? [],
            ];
        }

        /**
         * Show update message if license is required
         */
        public function update_message($plugin_data, $response): void {
            if (!$this->is_active()) {
                echo '<br><span style="color: #d63638;">' .
                    esc_html__('A valid license is required to receive updates.', 'peanut-license-client') .
                    '</span>';
            }
        }

        /**
         * Admin notices
         */
        public function admin_notices(): void {
            // Only show on plugin pages
            $screen = get_current_screen();
            if (!$screen || !in_array($screen->base, ['plugins', 'update-core'])) {
                return;
            }

            $status = get_option($this->config['status_option'], '');

            if ($status === 'expired') {
                printf(
                    '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
                    sprintf(
                        esc_html__('Your %s license has expired.', 'peanut-license-client'),
                        esc_html($this->config['plugin_name'])
                    ),
                    esc_url(admin_url('options-general.php?page=' . $this->config['plugin_slug'])),
                    esc_html__('Renew now', 'peanut-license-client')
                );
            }
        }

        /**
         * Render license field for settings page
         */
        public function render_license_field(): void {
            $license_key = $this->get_license_key();
            $status = get_option($this->config['status_option'], '');
            $license_data = $this->get_license_data();

            $masked_key = $license_key ? substr($license_key, 0, 4) . '-****-****-' . substr($license_key, -4) : '';
            ?>
            <div class="peanut-license-field" data-plugin="<?php echo esc_attr($this->config['plugin_slug']); ?>">
                <div class="peanut-license-input-wrap">
                    <input type="text"
                           name="<?php echo esc_attr($this->config['license_option']); ?>"
                           id="<?php echo esc_attr($this->config['license_option']); ?>"
                           value="<?php echo esc_attr($license_key ? $masked_key : ''); ?>"
                           class="regular-text"
                           placeholder="XXXX-XXXX-XXXX-XXXX"
                           <?php echo $status === 'active' ? 'readonly' : ''; ?>
                    />

                    <?php if ($status === 'active'): ?>
                        <button type="button" class="button peanut-deactivate-license">
                            <?php esc_html_e('Deactivate', 'peanut-license-client'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" class="button button-primary peanut-activate-license">
                            <?php esc_html_e('Activate', 'peanut-license-client'); ?>
                        </button>
                    <?php endif; ?>

                    <button type="button" class="button peanut-check-license">
                        <?php esc_html_e('Check Status', 'peanut-license-client'); ?>
                    </button>
                </div>

                <div class="peanut-license-status">
                    <?php if ($status === 'active' && $license_data): ?>
                        <span class="peanut-status-badge peanut-status-active">
                            <?php esc_html_e('Active', 'peanut-license-client'); ?>
                        </span>
                        <span class="peanut-tier">
                            <?php echo esc_html($license_data['tier_name'] ?? ucfirst($license_data['tier'] ?? 'free')); ?>
                        </span>
                        <?php if (!empty($license_data['expires_at_formatted'])): ?>
                            <span class="peanut-expiry">
                                <?php printf(
                                    esc_html__('Expires: %s', 'peanut-license-client'),
                                    esc_html($license_data['expires_at_formatted'])
                                ); ?>
                            </span>
                        <?php endif; ?>
                        <span class="peanut-activations">
                            <?php printf(
                                esc_html__('Sites: %d / %d', 'peanut-license-client'),
                                $license_data['activations_used'] ?? 0,
                                $license_data['activations_limit'] ?? 1
                            ); ?>
                        </span>
                    <?php elseif ($status === 'expired'): ?>
                        <span class="peanut-status-badge peanut-status-expired">
                            <?php esc_html_e('Expired', 'peanut-license-client'); ?>
                        </span>
                    <?php elseif ($status === 'invalid'): ?>
                        <span class="peanut-status-badge peanut-status-invalid">
                            <?php esc_html_e('Invalid', 'peanut-license-client'); ?>
                        </span>
                    <?php else: ?>
                        <span class="peanut-status-badge peanut-status-inactive">
                            <?php esc_html_e('Not Activated', 'peanut-license-client'); ?>
                        </span>
                    <?php endif; ?>
                </div>

                <p class="description">
                    <?php esc_html_e('Enter your license key to enable premium features and receive updates.', 'peanut-license-client'); ?>
                </p>
            </div>

            <style>
                .peanut-license-field { max-width: 600px; }
                .peanut-license-input-wrap { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; }
                .peanut-license-status { display: flex; gap: 12px; align-items: center; margin-bottom: 8px; }
                .peanut-status-badge { padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: 500; }
                .peanut-status-active { background: #d4edda; color: #155724; }
                .peanut-status-expired { background: #fff3cd; color: #856404; }
                .peanut-status-invalid { background: #f8d7da; color: #721c24; }
                .peanut-status-inactive { background: #e2e3e5; color: #383d41; }
                .peanut-tier { font-weight: 600; }
                .peanut-expiry, .peanut-activations { color: #666; font-size: 13px; }
            </style>

            <script>
            jQuery(function($) {
                var plugin = '<?php echo esc_js($this->config['plugin_slug']); ?>';
                var $field = $('.peanut-license-field[data-plugin="' + plugin + '"]');

                $field.on('click', '.peanut-activate-license', function() {
                    var $btn = $(this);
                    var key = $field.find('input').val();

                    if (!key) {
                        alert('<?php echo esc_js(__('Please enter a license key.', 'peanut-license-client')); ?>');
                        return;
                    }

                    $btn.prop('disabled', true).text('<?php echo esc_js(__('Activating...', 'peanut-license-client')); ?>');

                    $.post(ajaxurl, {
                        action: 'peanut_activate_license_' + plugin,
                        license_key: key,
                        _wpnonce: '<?php echo wp_create_nonce('peanut_license_' . $this->config['plugin_slug']); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || '<?php echo esc_js(__('Activation failed.', 'peanut-license-client')); ?>');
                            $btn.prop('disabled', false).text('<?php echo esc_js(__('Activate', 'peanut-license-client')); ?>');
                        }
                    });
                });

                $field.on('click', '.peanut-deactivate-license', function() {
                    if (!confirm('<?php echo esc_js(__('Are you sure you want to deactivate this license?', 'peanut-license-client')); ?>')) {
                        return;
                    }

                    var $btn = $(this);
                    $btn.prop('disabled', true).text('<?php echo esc_js(__('Deactivating...', 'peanut-license-client')); ?>');

                    $.post(ajaxurl, {
                        action: 'peanut_deactivate_license_' + plugin,
                        _wpnonce: '<?php echo wp_create_nonce('peanut_license_' . $this->config['plugin_slug']); ?>'
                    }, function(response) {
                        location.reload();
                    });
                });

                $field.on('click', '.peanut-check-license', function() {
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('<?php echo esc_js(__('Checking...', 'peanut-license-client')); ?>');

                    $.post(ajaxurl, {
                        action: 'peanut_check_license_' + plugin,
                        _wpnonce: '<?php echo wp_create_nonce('peanut_license_' . $this->config['plugin_slug']); ?>'
                    }, function(response) {
                        location.reload();
                    });
                });
            });
            </script>
            <?php
        }

        /**
         * AJAX: Activate license
         */
        public function ajax_activate_license(): void {
            check_ajax_referer('peanut_license_' . $this->config['plugin_slug']);

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permission denied.', 'peanut-license-client')]);
            }

            $license_key = sanitize_text_field($_POST['license_key'] ?? '');
            $result = $this->activate($license_key);

            if ($result['success']) {
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result);
            }
        }

        /**
         * AJAX: Deactivate license
         */
        public function ajax_deactivate_license(): void {
            check_ajax_referer('peanut_license_' . $this->config['plugin_slug']);

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permission denied.', 'peanut-license-client')]);
            }

            $result = $this->deactivate();
            wp_send_json_success($result);
        }

        /**
         * AJAX: Check license status
         */
        public function ajax_check_license(): void {
            check_ajax_referer('peanut_license_' . $this->config['plugin_slug']);

            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => __('Permission denied.', 'peanut-license-client')]);
            }

            $result = $this->check_status(true);
            wp_send_json_success($result);
        }

        /**
         * Make API request
         */
        private function api_request(string $endpoint, array $params = [], string $method = 'POST'): array {
            $url = trailingslashit($this->config['api_url']) . $endpoint;

            $args = [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Peanut-Client' => 'sdk/' . self::VERSION,
                ],
            ];

            // Keep keys out of URLs on the header-capable license server.
            foreach (['license_key', 'license'] as $key_param) {
                if (!empty($params[$key_param])) {
                    $args['headers']['X-Peanut-License-Key'] = $params[$key_param];
                    unset($params[$key_param]);
                }
            }

            if ($endpoint === 'license/validate') {
                // Supply the fingerprint already bound by the license administrator.
                // Do not invent a new identity for existing hardware-locked licenses.
                $hardware_id = apply_filters('peanut_license_hardware_id', '', $this->config['plugin_slug']);
                if (is_string($hardware_id) && trim($hardware_id) !== '') {
                    $params['hardware_id'] = trim($hardware_id);
                }
            }

            if ($method === 'GET') {
                $url = add_query_arg($params, $url);
            } else {
                $args['method'] = 'POST';
                $args['body'] = wp_json_encode($params);
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                return [
                    'success' => false,
                    'error' => 'connection_error',
                    'message' => $response->get_error_message(),
                ];
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [
                    'success' => false,
                    'error' => 'invalid_response',
                    'message' => __('Invalid response from license server.', 'peanut-license-client'),
                ];
            }

            return $data;
        }

        /**
         * Cache license data
         */
        private function cache_license_data(array $data): void {
            $this->license_data = $data;
            set_transient(
                $this->config['status_option'] . '_data',
                $data,
                $this->config['cache_duration']
            );
        }

        /**
         * Clear license cache
         */
        private function clear_license_cache(): void {
            $this->license_data = null;
            delete_transient($this->config['status_option'] . '_data');
        }
    }
}
