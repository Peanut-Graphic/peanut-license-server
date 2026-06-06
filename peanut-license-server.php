<?php
/**
 * Plugin Name: Peanut License Server
 * Plugin URI: https://peanutgraphic.com/peanut-suite
 * Description: License management, validation, and update server for Peanut Suite WordPress plugin.
 * Version: 1.3.5
 * Author: Peanut Graphic
 * Author URI: https://peanutgraphic.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: peanut-license-server
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

// Plugin constants
define('PEANUT_LICENSE_SERVER_VERSION', '1.3.5');
define('PEANUT_LICENSE_SERVER_PATH', plugin_dir_path(__FILE__));
define('PEANUT_LICENSE_SERVER_URL', plugin_dir_url(__FILE__));
define('PEANUT_LICENSE_SERVER_BASENAME', plugin_basename(__FILE__));

// Download token signing helpers (extracted so the unit test suite can load
// them in isolation without booting the full plugin). Must be required before
// the immediate download handler below, which calls peanut_serve_plugin_download().
require_once __DIR__ . '/includes/download-token-functions.php';

/**
 * IMMEDIATE Download Handler - runs before anything else
 * This bypasses all WordPress processing to avoid 406 errors
 *
 * SECURITY: Requires signed token to prevent unauthorized downloads.
 * Token format: HMAC-SHA256(plugin|timestamp|license, secret_key)
 */
if (isset($_GET['peanut_download']) && $_GET['peanut_download'] === '1') {
    peanut_serve_plugin_download();
}

function peanut_serve_plugin_download(): void {
    // SECURITY: Sanitize plugin slug - only allow alphanumeric and hyphens
    $plugin = isset($_GET['plugin']) ? preg_replace('/[^a-z0-9-]/', '', strtolower($_GET['plugin'])) : 'peanut-suite';
    $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
    $license = isset($_GET['license']) ? sanitize_text_field($_GET['license']) : '';

    // Valid plugins whitelist
    $valid_plugins = ['peanut-suite', 'formflow', 'peanut-booker', 'peanut-connect', 'peanut-webcomic-engine'];
    if (!in_array($plugin, $valid_plugins, true)) {
        // Log suspicious request
        error_log(sprintf(
            'Peanut License Server SECURITY: Invalid plugin download attempt. Plugin: %s, IP: %s',
            $plugin,
            sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        ));
        status_header(400);
        wp_die('Unknown plugin.', 400);
    }

    // SECURITY: Verify download token
    if (empty($token) || !peanut_verify_download_token($plugin, $token, $license)) {
        // Log unauthorized download attempt
        error_log(sprintf(
            'Peanut License Server SECURITY: Unauthorized download attempt. Plugin: %s, IP: %s, Token: %s',
            $plugin,
            sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
            empty($token) ? 'missing' : 'invalid'
        ));
        status_header(403);
        wp_die('Unauthorized. Please use a valid download link.', 403);
    }

    // Find the download file
    $upload_dir = wp_upload_dir();
    $base_path = $upload_dir['basedir'] . "/{$plugin}/";
    $file = null;

    // Check for exact filename
    if (file_exists($base_path . "{$plugin}.zip")) {
        $file = $base_path . "{$plugin}.zip";
    } else {
        // Check for versioned filename
        $files = glob($base_path . "{$plugin}-*.zip");
        if (!empty($files)) {
            usort($files, function($a, $b) {
                return filemtime($b) - filemtime($a);
            });
            $file = $files[0];
        }
    }

    // Check alternative location
    if (!$file && file_exists(PEANUT_LICENSE_SERVER_PATH . "releases/{$plugin}.zip")) {
        $file = PEANUT_LICENSE_SERVER_PATH . "releases/{$plugin}.zip";
    }

    if (!$file || !file_exists($file)) {
        status_header(404);
        wp_die('Download file not found.', 404);
    }

    // SECURITY: Verify file is within expected directories (path traversal prevention)
    $real_file = realpath($file);
    $real_base = realpath($upload_dir['basedir']);
    $real_releases = realpath(PEANUT_LICENSE_SERVER_PATH . 'releases');

    $is_valid_path = false;
    if ($real_file && $real_base && strpos($real_file, $real_base) === 0) {
        $is_valid_path = true;
    }
    if ($real_file && $real_releases && strpos($real_file, $real_releases) === 0) {
        $is_valid_path = true;
    }

    if (!$is_valid_path) {
        error_log(sprintf(
            'Peanut License Server SECURITY: Path traversal attempt blocked. File: %s, IP: %s',
            $file,
            sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        ));
        status_header(403);
        wp_die('Access denied.', 403);
    }

    // Clean ALL output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Send download headers with security headers
    header('HTTP/1.1 200 OK');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    // Output file and exit immediately
    readfile($file);
    exit;
}

/**
 * Main Peanut License Server Class
 */
final class Peanut_License_Server {

    /**
     * Single instance
     */
    private static ?self $instance = null;

    /**
     * Get single instance
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies(): void {
        // Logger (load first so other classes can use it)
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-logger.php';

        // Core classes
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-license-manager.php';
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-license-validator.php';
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-update-server.php';
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-api-security.php';
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-api-endpoints.php';
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-webhook-handler.php';

        // Security & utilities
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-rate-limiter.php';
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-validation-logger.php';

        // Batch operations & notifications
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-batch-operations.php';
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-webhook-notifications.php';

        // Analytics & reporting
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-analytics.php';
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-audit-trail.php';
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-security-features.php';

        // GDPR compliance
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-gdpr-compliance.php';

        // ML-powered abuse detection
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-ml-abuse-detector.php';

        // Admin REST API
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-admin-rest-api.php';

        // WooCommerce integration (customer portal)
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-woocommerce-integration.php';

        // Subscription sync
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-subscription-sync.php';

        // Product bundles
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-product-bundles.php';

        // Affiliate system
        require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-affiliate-system.php';

        // Admin
        if (is_admin()) {
            require_once PEANUT_LICENSE_SERVER_PATH . 'admin/class-admin-dashboard.php';
        }

        // WP-CLI commands
        if (defined('WP_CLI') && WP_CLI) {
            require_once PEANUT_LICENSE_SERVER_PATH . 'includes/class-cli-commands.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Handle downloads via admin-ajax.php (bypasses Apache mod_negotiation 406 errors)
        add_action('wp_ajax_peanut_download_plugin', [$this, 'handle_ajax_download']);
        add_action('wp_ajax_nopriv_peanut_download_plugin', [$this, 'handle_ajax_download']);

        // Activation/deactivation
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        // Check for DB migrations
        add_action('admin_init', [$this, 'check_db_migrations']);

        // Init
        add_action('init', [$this, 'init']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        // ML training cron hook
        add_action('peanut_ml_train_abuse_model', ['Peanut_ML_Abuse_Detector', 'train_model']);

        // Initialize webhook notifications
        Peanut_Webhook_Notifications::init();

        // WooCommerce hooks
        add_action('woocommerce_order_status_completed', [$this, 'handle_order_completed']);
        add_action('woocommerce_subscription_status_active', [$this, 'handle_subscription_active']);
        add_action('woocommerce_subscription_status_expired', [$this, 'handle_subscription_expired']);
        add_action('woocommerce_subscription_status_cancelled', [$this, 'handle_subscription_cancelled']);

        // Admin
        if (is_admin()) {
            add_action('admin_menu', [$this, 'add_admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        }
    }

    /**
     * Plugin activation
     */
    public function activate(): void {
        $this->create_tables();
        $this->set_default_options();
        flush_rewrite_rules();

        // Schedule ML abuse detection training if class is available
        if (class_exists('Peanut_ML_Abuse_Detector')) {
            Peanut_ML_Abuse_Detector::schedule_training();
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate(): void {
        flush_rewrite_rules();

        // Unschedule ML abuse detection training if class is available
        if (class_exists('Peanut_ML_Abuse_Detector')) {
            Peanut_ML_Abuse_Detector::unschedule_training();
        }
    }

    /**
     * Create database tables
     */
    private function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Licenses table
        $licenses_table = $wpdb->prefix . 'peanut_licenses';
        $licenses_sql = "CREATE TABLE IF NOT EXISTS {$licenses_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            license_key VARCHAR(64) NOT NULL,
            license_key_hash VARCHAR(64) NOT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            subscription_id BIGINT UNSIGNED DEFAULT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            customer_email VARCHAR(255) NOT NULL,
            customer_name VARCHAR(255) DEFAULT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            tier ENUM('free', 'pro', 'agency') DEFAULT 'free',
            status ENUM('active', 'expired', 'suspended', 'revoked') DEFAULT 'active',
            max_activations INT UNSIGNED DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            UNIQUE KEY unique_license_key (license_key),
            KEY idx_license_key_hash (license_key_hash),
            KEY idx_customer_email (customer_email),
            KEY idx_status (status),
            KEY idx_user_id (user_id),
            KEY idx_order_id (order_id)
        ) {$charset_collate};";

        // Activations table
        $activations_table = $wpdb->prefix . 'peanut_activations';
        $activations_sql = "CREATE TABLE IF NOT EXISTS {$activations_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            license_id BIGINT UNSIGNED NOT NULL,
            site_url VARCHAR(255) NOT NULL,
            site_name VARCHAR(255) DEFAULT NULL,
            site_hash VARCHAR(64) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            plugin_version VARCHAR(20) DEFAULT NULL,
            wp_version VARCHAR(20) DEFAULT NULL,
            php_version VARCHAR(20) DEFAULT NULL,
            is_multisite TINYINT(1) DEFAULT 0,
            active_plugins INT DEFAULT 0,
            health_status ENUM('healthy', 'warning', 'critical', 'offline') DEFAULT 'healthy',
            health_errors TEXT DEFAULT NULL,
            activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_checked DATETIME DEFAULT NULL,
            deactivated_at DATETIME DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            UNIQUE KEY unique_activation (license_id, site_hash),
            KEY idx_license_id (license_id),
            KEY idx_site_hash (site_hash),
            KEY idx_is_active (is_active),
            KEY idx_health_status (health_status)
        ) {$charset_collate};";

        // Update logs table
        $logs_table = $wpdb->prefix . 'peanut_update_logs';
        $logs_sql = "CREATE TABLE IF NOT EXISTS {$logs_table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            license_id BIGINT UNSIGNED DEFAULT NULL,
            site_url VARCHAR(255) DEFAULT NULL,
            plugin_version VARCHAR(20) DEFAULT NULL,
            new_version VARCHAR(20) DEFAULT NULL,
            action ENUM('check', 'download', 'install') DEFAULT 'check',
            ip_address VARCHAR(45) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_license_id (license_id),
            KEY idx_created_at (created_at),
            KEY idx_action (action)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($licenses_sql);
        dbDelta($activations_sql);
        dbDelta($logs_sql);

        // Create validation logs table
        Peanut_Validation_Logger::create_table();

        // Create webhook logs table
        Peanut_Webhook_Notifications::create_log_table();

        // Create audit trail table
        Peanut_Audit_Trail::create_table();

        // Create security restrictions table
        Peanut_Security_Features::create_table();

        // Create GDPR requests table
        Peanut_GDPR_Compliance::create_table();

        // Create product bundles tables
        Peanut_Product_Bundles::create_tables();

        // Create affiliate system tables
        Peanut_Affiliate_System::create_tables();

        // Store DB version
        update_option('peanut_license_server_db_version', '1.5.0');
    }

    /**
     * Set default options
     */
    private function set_default_options(): void {
        $defaults = [
            'peanut_license_server_api_enabled' => true,
            'peanut_license_server_update_enabled' => true,
            'peanut_license_server_cache_duration' => 12, // hours
            'peanut_license_server_plugin_version' => '1.0.0',
            'peanut_license_server_plugin_requires_wp' => '6.0',
            'peanut_license_server_plugin_requires_php' => '8.0',
            'peanut_license_server_plugin_tested_wp' => '6.4',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Initialize plugin
     */
    public function init(): void {
        // Textdomain is now loaded in main peanut_license_server() function
    }

    /**
     * Handle plugin downloads via admin-ajax.php
     * This bypasses Apache mod_negotiation which causes 406 errors
     * URL: /wp-admin/admin-ajax.php?action=peanut_download_plugin&plugin=peanut-suite
     */
    public function handle_ajax_download(): void {
        // Verify nonce for security
        if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'peanut_download_plugin' ) ) {
            wp_die( __( 'Security check failed', 'peanut-license-server' ) );
        }

        $plugin = isset($_GET['plugin']) ? sanitize_text_field($_GET['plugin']) : 'peanut-suite';
        $license_key = isset($_GET['license']) ? sanitize_text_field($_GET['license']) : null;

        // Validate plugin slug
        if (!Peanut_Update_Server::is_valid_product($plugin)) {
            status_header(400);
            wp_die(__('Unknown plugin.', 'peanut-license-server'), 400);
        }

        // Check rate limit
        if (Peanut_Rate_Limiter::is_rate_limited('download')) {
            status_header(429);
            wp_die(__('Too many download requests. Please try again later.', 'peanut-license-server'), 429);
        }

        Peanut_Rate_Limiter::record_request('download');

        $update_server = new Peanut_Update_Server($plugin);
        $file = $update_server->get_download_file();

        if (!$file || !file_exists($file)) {
            status_header(404);
            wp_die(__('Download file not found.', 'peanut-license-server'), 404);
        }

        // Clean ALL output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Send proper download headers
        header('HTTP/1.1 200 OK');
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Output file and exit
        readfile($file);
        exit;
    }

    /**
     * Check and run database migrations
     */
    public function check_db_migrations(): void {
        $current_db_version = get_option('peanut_license_server_db_version', '1.0.0');

        // Migration: Add health columns to activations table (v1.4.0)
        if (version_compare($current_db_version, '1.4.0', '<')) {
            global $wpdb;
            $table = $wpdb->prefix . 'peanut_activations';

            // Check if health_status column exists
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'health_status'");

            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE {$table}
                    ADD COLUMN wp_version VARCHAR(20) DEFAULT NULL AFTER plugin_version,
                    ADD COLUMN php_version VARCHAR(20) DEFAULT NULL AFTER wp_version,
                    ADD COLUMN is_multisite TINYINT(1) DEFAULT 0 AFTER php_version,
                    ADD COLUMN active_plugins INT DEFAULT 0 AFTER is_multisite,
                    ADD COLUMN health_status ENUM('healthy', 'warning', 'critical', 'offline') DEFAULT 'healthy' AFTER active_plugins,
                    ADD COLUMN health_errors TEXT DEFAULT NULL AFTER health_status,
                    ADD INDEX idx_health_status (health_status)
                ");
            }

            update_option('peanut_license_server_db_version', '1.4.0');
        }

        // Migration: Add deactivated_at column to activations table (v1.5.0)
        if (version_compare($current_db_version, '1.5.0', '<')) {
            global $wpdb;
            $table = $wpdb->prefix . 'peanut_activations';

            // Check if deactivated_at column exists
            $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$table} LIKE 'deactivated_at'");

            if (empty($column_exists)) {
                $wpdb->query("ALTER TABLE {$table}
                    ADD COLUMN deactivated_at DATETIME DEFAULT NULL AFTER last_checked
                ");
            }

            update_option('peanut_license_server_db_version', '1.5.0');
        }
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        $api = new Peanut_API_Endpoints();
        $api->register_routes();
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu(): void {
        // React SPA - Main menu
        add_menu_page(
            __('License Server', 'peanut-license-server'),
            __('License Server', 'peanut-license-server'),
            'manage_options',
            'peanut-license-app',
            [$this, 'render_react_app'],
            'dashicons-admin-network',
            55
        );

        // Legacy menu - Dashboard
        add_menu_page(
            __('Peanut Licenses', 'peanut-license-server'),
            __('Licenses (Legacy)', 'peanut-license-server'),
            'manage_options',
            'peanut-dashboard',
            [Peanut_Admin_Dashboard::class, 'render_dashboard_page'],
            'dashicons-admin-network',
            56
        );

        // Dashboard submenu (replaces auto-generated first item)
        add_submenu_page(
            'peanut-dashboard',
            __('Dashboard', 'peanut-license-server'),
            __('Dashboard', 'peanut-license-server'),
            'manage_options',
            'peanut-dashboard',
            [Peanut_Admin_Dashboard::class, 'render_dashboard_page']
        );

        // All Licenses
        add_submenu_page(
            'peanut-dashboard',
            __('All Licenses', 'peanut-license-server'),
            __('All Licenses', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses',
            [Peanut_Admin_Dashboard::class, 'render_licenses_page']
        );

        // License Map - visual tree view
        add_submenu_page(
            'peanut-dashboard',
            __('License Map', 'peanut-license-server'),
            __('License Map', 'peanut-license-server'),
            'manage_options',
            'peanut-license-map',
            [Peanut_Admin_Dashboard::class, 'render_license_map_page']
        );

        // Add New License
        add_submenu_page(
            'peanut-dashboard',
            __('Add New', 'peanut-license-server'),
            __('Add New', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses-add',
            [Peanut_Admin_Dashboard::class, 'render_add_license_page']
        );

        add_submenu_page(
            'peanut-dashboard',
            __('Settings', 'peanut-license-server'),
            __('Settings', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses-settings',
            [Peanut_Admin_Dashboard::class, 'render_settings_page']
        );

        add_submenu_page(
            'peanut-dashboard',
            __('Update Logs', 'peanut-license-server'),
            __('Update Logs', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses-logs',
            [Peanut_Admin_Dashboard::class, 'render_logs_page']
        );

        add_submenu_page(
            'peanut-dashboard',
            __('Batch Operations', 'peanut-license-server'),
            __('Batch Operations', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses-batch',
            [Peanut_Admin_Dashboard::class, 'render_batch_page']
        );

        add_submenu_page(
            'peanut-dashboard',
            __('Webhooks', 'peanut-license-server'),
            __('Webhooks', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses-webhooks',
            [Peanut_Admin_Dashboard::class, 'render_webhooks_page']
        );

        add_submenu_page(
            'peanut-dashboard',
            __('Security Logs', 'peanut-license-server'),
            __('Security Logs', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses-security',
            [Peanut_Admin_Dashboard::class, 'render_security_page']
        );

        add_submenu_page(
            'peanut-dashboard',
            __('Analytics', 'peanut-license-server'),
            __('Analytics', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses-analytics',
            [Peanut_Admin_Dashboard::class, 'render_analytics_page']
        );

        add_submenu_page(
            'peanut-dashboard',
            __('Site Health', 'peanut-license-server'),
            __('Site Health', 'peanut-license-server'),
            'manage_options',
            'peanut-site-health',
            [Peanut_Admin_Dashboard::class, 'render_site_health_page']
        );

        add_submenu_page(
            'peanut-dashboard',
            __('Audit Trail', 'peanut-license-server'),
            __('Audit Trail', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses-audit',
            [Peanut_Admin_Dashboard::class, 'render_audit_page']
        );

        add_submenu_page(
            'peanut-dashboard',
            __('GDPR Tools', 'peanut-license-server'),
            __('GDPR Tools', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses-gdpr',
            [Peanut_Admin_Dashboard::class, 'render_gdpr_page']
        );

        add_submenu_page(
            'peanut-dashboard',
            __('Product Updates', 'peanut-license-server'),
            __('Product Updates', 'peanut-license-server'),
            'manage_options',
            'peanut-product-updates',
            [Peanut_Admin_Dashboard::class, 'render_product_updates_page']
        );

        // Hidden page for license transfer (use empty string instead of null for PHP 8.x compatibility)
        add_submenu_page(
            '', // Hidden from menu
            __('Transfer License', 'peanut-license-server'),
            __('Transfer License', 'peanut-license-server'),
            'manage_options',
            'peanut-licenses-transfer',
            [Peanut_Admin_Dashboard::class, 'render_transfer_page']
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets(?string $hook): void {
        // Check if this is the React SPA page
        if ($hook === 'toplevel_page_peanut-license-app') {
            $this->enqueue_react_assets();
            return;
        }

        // Load on all Peanut License pages
        if (empty($hook) || strpos($hook, 'peanut-') === false) {
            return;
        }

        wp_enqueue_style(
            'peanut-license-admin',
            PEANUT_LICENSE_SERVER_URL . 'admin/css/admin.css',
            [],
            PEANUT_LICENSE_SERVER_VERSION
        );

        // Enqueue Chart.js for analytics page
        if (strpos($hook, 'analytics') !== false) {
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
                [],
                '4.4.1',
                true
            );

            wp_enqueue_script(
                'peanut-analytics',
                PEANUT_LICENSE_SERVER_URL . 'admin/js/analytics.js',
                ['jquery', 'chartjs'],
                PEANUT_LICENSE_SERVER_VERSION,
                true
            );
        }

        wp_enqueue_script(
            'peanut-license-admin',
            PEANUT_LICENSE_SERVER_URL . 'admin/js/admin.js',
            ['jquery'],
            PEANUT_LICENSE_SERVER_VERSION,
            true
        );

        wp_localize_script('peanut-license-admin', 'peanutLicenseAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('peanut_license_admin'),
            'recheckNonce' => wp_create_nonce('peanut_recheck_file'),
            'strings' => [
                'confirmDelete' => __('Are you sure you want to delete this license?', 'peanut-license-server'),
                'confirmDeactivate' => __('Are you sure you want to deactivate this site?', 'peanut-license-server'),
            ],
        ]);
    }

    /**
     * Enqueue React SPA assets
     */
    private function enqueue_react_assets(): void {
        $dist_path = PEANUT_LICENSE_SERVER_DIR . 'assets/dist/';
        $dist_url = PEANUT_LICENSE_SERVER_URL . 'assets/dist/';

        // Check if built assets exist
        if (!file_exists($dist_path . 'js/main.js')) {
            return;
        }

        // Enqueue the React app
        wp_enqueue_script(
            'peanut-license-react',
            $dist_url . 'js/main.js',
            [],
            PEANUT_LICENSE_SERVER_VERSION,
            true
        );

        // Add module type
        add_filter('script_loader_tag', function($tag, $handle) {
            if ($handle === 'peanut-license-react') {
                $tag = str_replace('<script ', '<script type="module" ', $tag);
            }
            return $tag;
        }, 10, 2);

        // Enqueue CSS
        if (file_exists($dist_path . 'css/main.css')) {
            wp_enqueue_style(
                'peanut-license-react-styles',
                $dist_url . 'css/main.css',
                [],
                PEANUT_LICENSE_SERVER_VERSION
            );
        }

        // Pass config to JavaScript
        wp_localize_script('peanut-license-react', 'peanutLicenseServer', [
            'apiUrl' => rest_url('peanut-api/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => PEANUT_LICENSE_SERVER_VERSION,
        ]);
    }

    /**
     * Render React app container
     */
    public function render_react_app(): void {
        // App container within WordPress admin
        echo '<div id="root" class="wrap peanut-license-wrap"></div>';

        // Style to fit within WP admin layout
        echo '<style>
            .peanut-license-wrap {
                margin: 0 !important;
                padding: 0 !important;
                margin-left: -20px !important;
                margin-right: -20px !important;
                margin-top: -10px !important;
                min-height: calc(100vh - 32px);
                background: #f8fafc;
            }
            #wpbody-content {
                padding-bottom: 0 !important;
            }
        </style>';
    }

    /**
     * Handle WooCommerce order completed
     */
    public function handle_order_completed(int $order_id): void {
        $handler = new Peanut_Webhook_Handler();
        $handler->process_order_completed($order_id);
    }

    /**
     * Handle subscription activated
     */
    public function handle_subscription_active($subscription): void {
        $handler = new Peanut_Webhook_Handler();
        $handler->process_subscription_active($subscription);
    }

    /**
     * Handle subscription expired
     */
    public function handle_subscription_expired($subscription): void {
        $handler = new Peanut_Webhook_Handler();
        $handler->process_subscription_expired($subscription);
    }

    /**
     * Handle subscription cancelled
     */
    public function handle_subscription_cancelled($subscription): void {
        $handler = new Peanut_Webhook_Handler();
        $handler->process_subscription_cancelled($subscription);
    }
}

/**
 * Initialize the plugin
 *
 * Uses 'init' hook instead of 'plugins_loaded' to ensure translations
 * are properly loaded before any translation functions are called.
 * WordPress 6.7+ enforces strict timing on textdomain loading.
 */
function peanut_license_server(): Peanut_License_Server {
    // Load textdomain first
    load_plugin_textdomain(
        'peanut-license-server',
        false,
        dirname(PEANUT_LICENSE_SERVER_BASENAME) . '/languages'
    );

    return Peanut_License_Server::get_instance();
}

// Start the plugin
add_action('init', 'peanut_license_server', 0);
