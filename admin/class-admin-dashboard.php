<?php
/**
 * Admin Dashboard Class
 *
 * Handles the WordPress admin interface for license management.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_Admin_Dashboard {

    /**
     * Render main dashboard page
     */
    public static function render_dashboard_page(): void {
        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/dashboard.php';
    }

    /**
     * Render license map page - visual tree view of licenses and sites
     */
    public static function render_license_map_page(): void {
        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/license-map.php';
    }

    /**
     * Render licenses list page
     */
    public static function render_licenses_page(): void {
        // Handle actions
        self::handle_license_actions();

        // Get filters
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $tier = isset($_GET['tier']) ? sanitize_text_field($_GET['tier']) : '';
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;

        // Get licenses
        $result = Peanut_License_Manager::get_all([
            'page' => $page,
            'per_page' => 20,
            'status' => $status,
            'tier' => $tier,
            'search' => $search,
        ]);

        $licenses = $result['data'];
        $total = $result['total'];
        $total_pages = $result['total_pages'];

        // Get statistics
        $stats = Peanut_License_Manager::get_statistics();

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/licenses-list.php';
    }

    /**
     * Render add license page
     */
    public static function render_add_license_page(): void {
        $errors = [];
        $success = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['peanut_add_license_nonce'])) {
            if (!wp_verify_nonce($_POST['peanut_add_license_nonce'], 'peanut_add_license')) {
                $errors[] = __('Security check failed.', 'peanut-license-server');
            } else {
                $result = self::process_add_license();
                if (is_wp_error($result)) {
                    $errors[] = $result->get_error_message();
                } else {
                    $success = $result;
                }
            }
        }

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/add-license.php';
    }

    /**
     * Render settings page
     */
    public static function render_settings_page(): void {
        $saved = false;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['peanut_settings_nonce'])) {
            if (wp_verify_nonce($_POST['peanut_settings_nonce'], 'peanut_save_settings')) {
                self::save_settings();
                $saved = true;
            }
        }

        $settings = [
            'api_enabled' => get_option('peanut_license_server_api_enabled', true),
            'update_enabled' => get_option('peanut_license_server_update_enabled', true),
            'cache_duration' => get_option('peanut_license_server_cache_duration', 12),
            'plugin_version' => get_option('peanut_license_server_plugin_version', '1.0.0'),
            'requires_wp' => get_option('peanut_license_server_plugin_requires_wp', '6.0'),
            'requires_php' => get_option('peanut_license_server_plugin_requires_php', '8.0'),
            'tested_wp' => get_option('peanut_license_server_plugin_tested_wp', '6.4'),
        ];

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/settings.php';
    }

    /**
     * Render logs page
     */
    public static function render_logs_page(): void {
        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $stats = Peanut_Update_Server::get_statistics(['days' => $days]);

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/logs.php';
    }

    /**
     * Render batch operations page
     */
    public static function render_batch_page(): void {
        $results = null;
        $action_type = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['peanut_batch_nonce']) || !wp_verify_nonce($_POST['peanut_batch_nonce'], 'peanut_batch_operations')) {
                wp_die(__('Security check failed.', 'peanut-license-server'));
            }

            $action_type = sanitize_text_field($_POST['batch_action'] ?? '');

            switch ($action_type) {
                case 'export_csv':
                    $csv = Peanut_Batch_Operations::export_csv($_POST);
                    Peanut_Batch_Operations::download_export($csv, 'licenses-' . date('Y-m-d') . '.csv', 'csv');
                    break;

                case 'export_json':
                    $json = Peanut_Batch_Operations::export_json($_POST);
                    Peanut_Batch_Operations::download_export($json, 'licenses-' . date('Y-m-d') . '.json', 'json');
                    break;

                case 'import_csv':
                    if (!empty($_FILES['import_file']['tmp_name'])) {
                        $content = file_get_contents($_FILES['import_file']['tmp_name']);
                        $results = Peanut_Batch_Operations::import_csv($content);
                    }
                    break;

                case 'import_json':
                    if (!empty($_FILES['import_file']['tmp_name'])) {
                        $content = file_get_contents($_FILES['import_file']['tmp_name']);
                        $results = Peanut_Batch_Operations::import_json($content);
                    }
                    break;

                case 'generate_bulk':
                    $count = intval($_POST['bulk_count'] ?? 10);
                    $results = Peanut_Batch_Operations::generate_bulk($count, [
                        'customer_email' => sanitize_email($_POST['bulk_email'] ?? ''),
                        'customer_name' => sanitize_text_field($_POST['bulk_name'] ?? ''),
                        'tier' => sanitize_text_field($_POST['bulk_tier'] ?? 'pro'),
                        'product_id' => 0,
                        'expires_at' => (isset($_POST['bulk_expires']) && $_POST['bulk_expires'] !== '') ? sanitize_text_field($_POST['bulk_expires']) : null,
                    ]);
                    break;
            }
        }

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/batch-operations.php';
    }

    /**
     * Render webhooks page
     */
    public static function render_webhooks_page(): void {
        $message = '';
        $message_type = '';

        // Handle actions
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['peanut_webhook_nonce'])) {
            if (!wp_verify_nonce($_POST['peanut_webhook_nonce'], 'peanut_webhook_action')) {
                wp_die(__('Security check failed.', 'peanut-license-server'));
            }

            $action = sanitize_text_field($_POST['webhook_action'] ?? '');

            switch ($action) {
                case 'add':
                    $url = esc_url_raw($_POST['webhook_url'] ?? '');
                    $events = isset($_POST['webhook_events']) ? array_map('sanitize_text_field', $_POST['webhook_events']) : [];

                    if (!empty($url)) {
                        Peanut_Webhook_Notifications::add_endpoint($url, ['events' => $events]);
                        $message = __('Webhook endpoint added.', 'peanut-license-server');
                        $message_type = 'success';
                    }
                    break;

                case 'delete':
                    $id = sanitize_text_field($_POST['webhook_id'] ?? '');
                    if (!empty($id)) {
                        Peanut_Webhook_Notifications::remove_endpoint($id);
                        $message = __('Webhook endpoint removed.', 'peanut-license-server');
                        $message_type = 'success';
                    }
                    break;

                case 'test':
                    $url = esc_url_raw($_POST['test_url'] ?? '');
                    $secret = sanitize_text_field($_POST['test_secret'] ?? '');

                    if (!empty($url)) {
                        $result = Peanut_Webhook_Notifications::send_test_webhook($url, $secret);
                        if ($result['success']) {
                            $message = sprintf(__('Test webhook sent. Response code: %d', 'peanut-license-server'), $result['status_code']);
                            $message_type = 'success';
                        } else {
                            $message = sprintf(__('Test failed: %s', 'peanut-license-server'), $result['error']);
                            $message_type = 'error';
                        }
                    }
                    break;
            }
        }

        $endpoints = Peanut_Webhook_Notifications::get_webhook_endpoints();
        $available_events = Peanut_Webhook_Notifications::get_available_events();

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/webhooks.php';
    }

    /**
     * Render security logs page
     */
    public static function render_security_page(): void {
        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        // Get validation logs
        $logs = Peanut_Validation_Logger::get_logs([
            'page' => $page,
            'per_page' => 50,
            'status' => $status_filter,
        ]);

        // Get security statistics
        $stats = Peanut_Validation_Logger::get_statistics($days);

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/security-logs.php';
    }

    /**
     * Render license transfer form
     */
    public static function render_transfer_page(): void {
        $message = '';
        $message_type = '';
        $license = null;

        $license_id = isset($_GET['license_id']) ? intval($_GET['license_id']) : 0;

        if ($license_id) {
            $license = Peanut_License_Manager::get_by_id($license_id);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['peanut_transfer_nonce'])) {
            if (!wp_verify_nonce($_POST['peanut_transfer_nonce'], 'peanut_transfer_license')) {
                wp_die(__('Security check failed.', 'peanut-license-server'));
            }

            $license_id = intval($_POST['license_id'] ?? 0);
            $new_email = sanitize_email($_POST['new_email'] ?? '');
            $new_name = sanitize_text_field($_POST['new_name'] ?? '');
            $deactivate_sites = isset($_POST['deactivate_sites']) && $_POST['deactivate_sites'] !== '';

            if ($license_id && !empty($new_email)) {
                $result = Peanut_License_Manager::transfer_license($license_id, [
                    'email' => $new_email,
                    'name' => $new_name,
                    'deactivate_sites' => $deactivate_sites,
                ]);

                if ($result) {
                    $message = __('License transferred successfully.', 'peanut-license-server');
                    $message_type = 'success';
                    $license = $result;
                } else {
                    $message = __('Transfer failed.', 'peanut-license-server');
                    $message_type = 'error';
                }
            }
        }

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/transfer-license.php';
    }

    /**
     * Handle license actions (delete, suspend, etc.)
     */
    private static function handle_license_actions(): void {
        if (!isset($_GET['action']) || !isset($_GET['license_id'])) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'peanut_license_action')) {
            return;
        }

        $action = sanitize_text_field($_GET['action']);
        $license_id = intval($_GET['license_id']);

        switch ($action) {
            case 'delete':
                Peanut_License_Manager::delete($license_id);
                break;
            case 'suspend':
                Peanut_License_Manager::suspend($license_id);
                break;
            case 'revoke':
                Peanut_License_Manager::revoke($license_id);
                break;
            case 'reactivate':
                Peanut_License_Manager::reactivate($license_id);
                break;
            case 'regenerate':
                Peanut_License_Manager::regenerate_key($license_id);
                break;
            case 'resend_email':
                Peanut_License_Manager::resend_license_email($license_id);
                break;
        }

        wp_redirect(remove_query_arg(['action', 'license_id', '_wpnonce']));
        exit;
    }

    /**
     * Process add license form
     */
    private static function process_add_license() {
        $email = sanitize_email($_POST['customer_email'] ?? '');
        $name = sanitize_text_field($_POST['customer_name'] ?? '');
        $tier = sanitize_text_field($_POST['tier'] ?? 'pro');
        $expires = sanitize_text_field($_POST['expires_at'] ?? '');

        if (empty($email) || !is_email($email)) {
            return new WP_Error('invalid_email', __('Please enter a valid email address.', 'peanut-license-server'));
        }

        $expires_at = null;
        if (!empty($expires)) {
            $expires_at = date('Y-m-d H:i:s', strtotime($expires));
        } else {
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 year'));
        }

        $license = Peanut_License_Manager::create([
            'customer_email' => $email,
            'customer_name' => $name,
            'tier' => $tier,
            'product_id' => 0,
            'expires_at' => $expires_at,
        ]);

        if (!$license) {
            return new WP_Error('create_failed', __('Failed to create license.', 'peanut-license-server'));
        }

        // Send email if requested
        if (isset($_POST['send_email']) && $_POST['send_email'] !== '') {
            Peanut_License_Manager::send_license_email($license);
        }

        return $license;
    }

    /**
     * Save settings
     */
    private static function save_settings(): void {
        // Global settings
        update_option('peanut_license_server_api_enabled', isset($_POST['api_enabled']) && $_POST['api_enabled'] !== '');
        update_option('peanut_license_server_update_enabled', isset($_POST['update_enabled']) && $_POST['update_enabled'] !== '');
        update_option('peanut_license_server_cache_duration', intval($_POST['cache_duration'] ?? 12));

        // Product-specific settings
        $product_slug = sanitize_text_field($_POST['product_slug'] ?? 'peanut-suite');

        if (Peanut_Update_Server::is_valid_product($product_slug)) {
            $version = sanitize_text_field($_POST['product_version'] ?? '1.0.0');

            update_option("peanut_{$product_slug}_version", $version);
            update_option("peanut_{$product_slug}_requires_wp", sanitize_text_field($_POST['product_requires_wp'] ?? '6.0'));
            update_option("peanut_{$product_slug}_requires_php", sanitize_text_field($_POST['product_requires_php'] ?? '8.0'));
            update_option("peanut_{$product_slug}_tested_wp", sanitize_text_field($_POST['product_tested_wp'] ?? '6.4'));
            update_option("peanut_{$product_slug}_last_updated", date('Y-m-d'));

            // Handle changelog update
            if (isset($_POST['new_changelog']) && $_POST['new_changelog'] !== '') {
                $existing_changelog = get_option("peanut_{$product_slug}_changelog", '');
                $new_changelog = "<h4>{$version}</h4>\n<ul>\n";
                foreach (explode("\n", sanitize_textarea_field($_POST['new_changelog'])) as $line) {
                    $line = trim($line);
                    if (!empty($line)) {
                        $line = ltrim($line, '- ');
                        $new_changelog .= "<li>{$line}</li>\n";
                    }
                }
                $new_changelog .= "</ul>\n\n{$existing_changelog}";
                update_option("peanut_{$product_slug}_changelog", $new_changelog);
            }
        }
    }

    /**
     * AJAX handler for deactivating a site
     */
    public static function ajax_deactivate_site(): void {
        check_ajax_referer('peanut_license_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $activation_id = intval($_POST['activation_id'] ?? 0);

        if (!$activation_id) {
            wp_send_json_error(['message' => 'Invalid activation ID']);
        }

        $result = Peanut_License_Manager::deactivate_site($activation_id);

        if ($result) {
            wp_send_json_success(['message' => 'Site deactivated']);
        } else {
            wp_send_json_error(['message' => 'Failed to deactivate site']);
        }
    }

    /**
     * AJAX handler for rechecking plugin file
     */
    public static function ajax_recheck_plugin_file(): void {
        check_ajax_referer('peanut_recheck_file', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $product = sanitize_text_field($_POST['product'] ?? 'peanut-suite');

        if (!Peanut_Update_Server::is_valid_product($product)) {
            $product = 'peanut-suite';
        }

        $update_server = new Peanut_Update_Server($product);
        $file_info = $update_server->get_download_file_info();

        ob_start();
        if ($file_info): ?>
            <p>
                <span class="dashicons dashicons-yes-alt" style="color: green;"></span>
                <?php
                printf(
                    esc_html__('%s found (%s)', 'peanut-license-server'),
                    '<strong>' . esc_html($file_info['filename']) . '</strong>',
                    size_format($file_info['size'])
                );
                ?>
                <br>
                <small style="color: #666;">
                    <?php
                    printf(
                        esc_html__('Last modified: %s', 'peanut-license-server'),
                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $file_info['modified'])
                    );
                    ?>
                </small>
            </p>
        <?php else: ?>
            <p>
                <span class="dashicons dashicons-warning" style="color: orange;"></span>
                <?php printf(esc_html__('No plugin ZIP found. Upload %s.zip or %s-x.x.x.zip to enable downloads.', 'peanut-license-server'), esc_html($product), esc_html($product)); ?>
            </p>
        <?php endif;
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }

    /**
     * Render analytics page
     */
    public static function render_analytics_page(): void {
        $days = isset($_GET['days']) ? intval($_GET['days']) : 30;

        // Get dashboard stats
        $stats = Peanut_Analytics::get_dashboard_stats();

        // Get timeline data
        $timeline_data = Peanut_Analytics::get_timeline_data($days, 'licenses');
        $validations_timeline = Peanut_Analytics::get_timeline_data($days, 'validations');

        // Get distributions
        $tier_distribution = Peanut_Analytics::get_tier_distribution();
        $product_distribution = Peanut_Analytics::get_product_distribution();

        // Get top sites and version adoption
        $top_sites = Peanut_Analytics::get_top_sites(10);
        $version_adoption = Peanut_Analytics::get_version_adoption();

        // Get recent activity and expiring licenses
        $recent_activity = Peanut_Analytics::get_recent_activity(15);
        $expiring_licenses = Peanut_Analytics::get_expiring_licenses(30);

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/analytics.php';
    }

    /**
     * Render site health page
     */
    public static function render_site_health_page(): void {
        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/site-health.php';
    }

    /**
     * Render audit trail page
     */
    public static function render_audit_page(): void {
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $event_filter = isset($_GET['event']) ? sanitize_text_field($_GET['event']) : '';
        $license_filter = isset($_GET['license_id']) ? intval($_GET['license_id']) : 0;
        $user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

        // Get audit logs
        $args = [
            'page' => $page,
            'per_page' => 50,
        ];

        if ($event_filter) {
            $args['event'] = $event_filter;
        }
        if ($license_filter) {
            $args['license_id'] = $license_filter;
        }
        if ($user_filter) {
            $args['user_id'] = $user_filter;
        }

        $result = Peanut_Audit_Trail::get_logs($args);

        // Get available event types for filter
        $event_types = [
            Peanut_Audit_Trail::EVENT_LICENSE_CREATED => __('License Created', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_LICENSE_UPDATED => __('License Updated', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_LICENSE_DELETED => __('License Deleted', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_STATUS_CHANGED => __('Status Changed', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_SITE_ACTIVATED => __('Site Activated', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_SITE_DEACTIVATED => __('Site Deactivated', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_LICENSE_VALIDATED => __('License Validated', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_VALIDATION_FAILED => __('Validation Failed', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_LICENSE_TRANSFERRED => __('License Transferred', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_KEY_REGENERATED => __('Key Regenerated', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_TIER_CHANGED => __('Tier Changed', 'peanut-license-server'),
            Peanut_Audit_Trail::EVENT_EXPIRY_CHANGED => __('Expiry Changed', 'peanut-license-server'),
        ];

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/audit-trail.php';
    }

    /**
     * Render product updates page
     */
    public static function render_product_updates_page(): void {
        $message = '';
        $message_type = '';

        // Get selected product
        $selected_product = isset($_GET['product']) ? sanitize_text_field($_GET['product']) : 'peanut-booker';
        if (!Peanut_Update_Server::is_valid_product($selected_product)) {
            $selected_product = 'peanut-booker';
        }

        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['peanut_updates_nonce'])) {
            if (!wp_verify_nonce($_POST['peanut_updates_nonce'], 'peanut_product_updates')) {
                wp_die(__('Security check failed.', 'peanut-license-server'));
            }

            $action = sanitize_text_field($_POST['update_action'] ?? 'save');
            $product_slug = sanitize_text_field($_POST['product_slug'] ?? $selected_product);

            if (!Peanut_Update_Server::is_valid_product($product_slug)) {
                $product_slug = 'peanut-booker';
            }

            switch ($action) {
                case 'save':
                    // Save version info
                    $version = sanitize_text_field($_POST['version'] ?? '1.0.0');
                    if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
                        $message = __('Invalid version format. Use x.x.x format.', 'peanut-license-server');
                        $message_type = 'error';
                        break;
                    }

                    update_option("peanut_{$product_slug}_version", $version);
                    update_option("peanut_{$product_slug}_requires_wp", sanitize_text_field($_POST['requires_wp'] ?? '6.0'));
                    update_option("peanut_{$product_slug}_requires_php", sanitize_text_field($_POST['requires_php'] ?? '8.0'));
                    update_option("peanut_{$product_slug}_tested_wp", sanitize_text_field($_POST['tested_wp'] ?? '6.4'));
                    update_option("peanut_{$product_slug}_last_updated", date('Y-m-d'));

                    // Handle changelog
                    if (isset($_POST['changelog']) && $_POST['changelog'] !== '') {
                        $existing = get_option("peanut_{$product_slug}_changelog", '');
                        $new_changelog = "<h4>{$version}</h4>\n<ul>\n";
                        foreach (explode("\n", sanitize_textarea_field($_POST['changelog'])) as $line) {
                            $line = trim($line);
                            if (!empty($line)) {
                                $line = ltrim($line, '- ');
                                $new_changelog .= "<li>" . esc_html($line) . "</li>\n";
                            }
                        }
                        $new_changelog .= "</ul>\n\n{$existing}";
                        update_option("peanut_{$product_slug}_changelog", $new_changelog);
                    }

                    $message = sprintf(__('Product "%s" updated to version %s.', 'peanut-license-server'), $product_slug, $version);
                    $message_type = 'success';
                    break;

                case 'upload':
                    // Handle file upload
                    if (empty($_FILES['plugin_zip']['tmp_name'])) {
                        $message = __('Please select a ZIP file to upload.', 'peanut-license-server');
                        $message_type = 'error';
                        break;
                    }

                    $file = $_FILES['plugin_zip'];

                    // Validate file extension
                    $file_type = wp_check_filetype($file['name']);
                    if ($file_type['ext'] !== 'zip') {
                        $message = __('Only ZIP files are allowed.', 'peanut-license-server');
                        $message_type = 'error';
                        break;
                    }

                    // Validate actual MIME type of file content for security
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $actual_mime = $finfo->file($file['tmp_name']);
                    $allowed_mimes = ['application/zip', 'application/x-zip-compressed', 'application/x-zip'];
                    if (!in_array($actual_mime, $allowed_mimes, true)) {
                        $message = __('File content does not match ZIP format.', 'peanut-license-server');
                        $message_type = 'error';
                        break;
                    }

                    // Create upload directory
                    $upload_dir = wp_upload_dir();
                    $product_dir = $upload_dir['basedir'] . '/' . $product_slug;

                    if (!file_exists($product_dir)) {
                        wp_mkdir_p($product_dir);
                    }

                    // Delete old zip files
                    $old_files = glob($product_dir . '/*.zip');
                    foreach ($old_files as $old_file) {
                        unlink($old_file);
                    }

                    // Move uploaded file
                    $target_file = $product_dir . '/' . $product_slug . '.zip';
                    if (move_uploaded_file($file['tmp_name'], $target_file)) {
                        $message = sprintf(__('Plugin ZIP uploaded successfully to %s', 'peanut-license-server'), $target_file);
                        $message_type = 'success';
                    } else {
                        $message = __('Failed to upload file. Check directory permissions.', 'peanut-license-server');
                        $message_type = 'error';
                    }
                    break;
            }

            $selected_product = $product_slug;
        }

        // Get all products
        $products = Peanut_Update_Server::get_all_products();

        // Get current product settings
        $product_settings = [
            'version' => get_option("peanut_{$selected_product}_version", '1.0.0'),
            'requires_wp' => get_option("peanut_{$selected_product}_requires_wp", '6.0'),
            'requires_php' => get_option("peanut_{$selected_product}_requires_php", '8.0'),
            'tested_wp' => get_option("peanut_{$selected_product}_tested_wp", '6.4'),
            'last_updated' => get_option("peanut_{$selected_product}_last_updated", ''),
            'changelog' => get_option("peanut_{$selected_product}_changelog", ''),
        ];

        // Get file info
        $update_server = new Peanut_Update_Server($selected_product);
        $file_info = $update_server->get_download_file_info();

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/product-updates.php';
    }

    /**
     * Render GDPR tools page
     */
    public static function render_gdpr_page(): void {
        $message = '';
        $message_type = '';
        $export_data = null;

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['peanut_gdpr_nonce'])) {
            if (!wp_verify_nonce($_POST['peanut_gdpr_nonce'], 'peanut_gdpr_action')) {
                wp_die(__('Security check failed.', 'peanut-license-server'));
            }

            $action = sanitize_text_field($_POST['gdpr_action'] ?? '');
            $email = sanitize_email($_POST['customer_email'] ?? '');

            if (empty($email)) {
                $message = __('Please provide a valid email address.', 'peanut-license-server');
                $message_type = 'error';
            } else {
                switch ($action) {
                    case 'export':
                        $export_data = Peanut_GDPR_Compliance::export_customer_data($email);
                        if ($export_data) {
                            $message = __('Customer data exported successfully.', 'peanut-license-server');
                            $message_type = 'success';
                        } else {
                            $message = __('No data found for this email address.', 'peanut-license-server');
                            $message_type = 'warning';
                        }
                        break;

                    case 'download':
                        $export_data = Peanut_GDPR_Compliance::export_customer_data($email);
                        if ($export_data) {
                            Peanut_GDPR_Compliance::download_export($export_data, $email);
                        }
                        break;

                    case 'anonymize':
                        $confirm = isset($_POST['confirm_action']) && $_POST['confirm_action'] !== '';
                        if (!$confirm) {
                            $message = __('Please confirm the action by checking the confirmation box.', 'peanut-license-server');
                            $message_type = 'error';
                        } else {
                            $result = Peanut_GDPR_Compliance::anonymize_customer_data($email);
                            if ($result['success']) {
                                $message = sprintf(
                                    __('Customer data anonymized. %d licenses and %d activations affected.', 'peanut-license-server'),
                                    $result['licenses_updated'],
                                    $result['activations_updated']
                                );
                                $message_type = 'success';
                            } else {
                                $message = $result['error'];
                                $message_type = 'error';
                            }
                        }
                        break;

                    case 'delete':
                        $confirm = isset($_POST['confirm_action']) && $_POST['confirm_action'] !== '';
                        if (!$confirm) {
                            $message = __('Please confirm the action by checking the confirmation box.', 'peanut-license-server');
                            $message_type = 'error';
                        } else {
                            $result = Peanut_GDPR_Compliance::delete_customer_data($email);
                            if ($result['success']) {
                                $message = sprintf(
                                    __('Customer data deleted. %d licenses and %d activations removed.', 'peanut-license-server'),
                                    $result['licenses_deleted'],
                                    $result['activations_deleted']
                                );
                                $message_type = 'success';
                            } else {
                                $message = $result['error'];
                                $message_type = 'error';
                            }
                        }
                        break;
                }
            }
        }

        // Get pending GDPR requests
        $pending_requests = Peanut_GDPR_Compliance::get_pending_requests();

        include PEANUT_LICENSE_SERVER_PATH . 'admin/views/gdpr-tools.php';
    }

    /**
     * AJAX handler for dismissing info cards
     */
    public static function ajax_dismiss_info_card(): void {
        check_ajax_referer('peanut_license_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $card_id = sanitize_key($_POST['card_id'] ?? '');

        if (!$card_id) {
            wp_send_json_error(['message' => 'Invalid card ID']);
        }

        // Save dismissal preference in user meta
        update_user_meta(get_current_user_id(), 'peanut_dismissed_' . $card_id, true);

        wp_send_json_success(['message' => 'Card dismissed']);
    }
}

// Register AJAX handlers
add_action('wp_ajax_peanut_deactivate_site', ['Peanut_Admin_Dashboard', 'ajax_deactivate_site']);
add_action('wp_ajax_peanut_recheck_plugin_file', ['Peanut_Admin_Dashboard', 'ajax_recheck_plugin_file']);
add_action('wp_ajax_peanut_dismiss_info_card', ['Peanut_Admin_Dashboard', 'ajax_dismiss_info_card']);
