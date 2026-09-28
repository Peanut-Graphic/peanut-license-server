<?php
/**
 * GDPR Compliance Class
 *
 * Handles customer data export, anonymization, and deletion.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_GDPR_Compliance {

    /**
     * Request types
     */
    public const REQUEST_EXPORT = 'export';
    public const REQUEST_ANONYMIZE = 'anonymize';
    public const REQUEST_DELETE = 'delete';

    /**
     * Request statuses
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Get requests table name
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_gdpr_requests';
    }

    /**
     * Export all customer data
     */
    public static function export_customer_data(string $email): ?array {
        global $wpdb;

        $email = sanitize_email($email);
        if (empty($email)) {
            return null;
        }

        $licenses_table = $wpdb->prefix . 'peanut_licenses';
        $activations_table = $wpdb->prefix . 'peanut_activations';
        $logs_table = $wpdb->prefix . 'peanut_update_logs';
        $validation_logs_table = $wpdb->prefix . 'peanut_validation_logs';
        $audit_table = $wpdb->prefix . 'peanut_audit_trail';

        // Get licenses
        $licenses = $wpdb->get_results($wpdb->prepare(
            "SELECT id, license_key, tier, status, max_activations, created_at, expires_at, customer_name
             FROM {$licenses_table}
             WHERE customer_email = %s",
            $email
        ));

        if (empty($licenses)) {
            return null;
        }

        $license_ids = wp_list_pluck($licenses, 'id');
        $license_ids_placeholder = implode(',', array_fill(0, count($license_ids), '%d'));

        // Get activations
        $activations = $wpdb->get_results($wpdb->prepare(
            "SELECT license_id, site_url, site_name, ip_address, plugin_version, activated_at, last_checked, is_active
             FROM {$activations_table}
             WHERE license_id IN ({$license_ids_placeholder})",
            ...$license_ids
        ));

        // Get update logs
        $update_logs = $wpdb->get_results($wpdb->prepare(
            "SELECT license_id, site_url, plugin_version, new_version, action, ip_address, created_at
             FROM {$logs_table}
             WHERE license_id IN ({$license_ids_placeholder})
             ORDER BY created_at DESC
             LIMIT 1000",
            ...$license_ids
        ));

        // Get validation logs
        $validation_logs = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '{$validation_logs_table}'")) {
            $validation_logs = $wpdb->get_results($wpdb->prepare(
                "SELECT license_key_partial, site_url, ip_address, status, error_code, created_at
                 FROM {$validation_logs_table}
                 WHERE license_key_hash IN (SELECT license_key_hash FROM {$licenses_table} WHERE customer_email = %s)
                 ORDER BY created_at DESC
                 LIMIT 1000",
                $email
            ));
        }

        // Get audit trail
        $audit_logs = [];
        if ($wpdb->get_var("SHOW TABLES LIKE '{$audit_table}'")) {
            $audit_logs = $wpdb->get_results($wpdb->prepare(
                "SELECT event, license_id, data, ip_address, created_at
                 FROM {$audit_table}
                 WHERE license_id IN ({$license_ids_placeholder})
                 ORDER BY created_at DESC
                 LIMIT 500",
                ...$license_ids
            ));
        }

        // Log this export
        self::log_request($email, self::REQUEST_EXPORT, self::STATUS_COMPLETED);

        return [
            'exported_at' => current_time('mysql'),
            'customer_email' => $email,
            'licenses' => $licenses,
            'activations' => $activations,
            'update_logs' => $update_logs,
            'validation_logs' => $validation_logs,
            'audit_logs' => $audit_logs,
        ];
    }

    /**
     * Download export as JSON file
     */
    public static function download_export(array $data, string $email): void {
        $filename = 'customer-data-' . sanitize_file_name($email) . '-' . date('Y-m-d') . '.json';

        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen(wp_json_encode($data, JSON_PRETTY_PRINT)));

        echo wp_json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Anonymize customer data (GDPR right to be forgotten - soft version)
     */
    public static function anonymize_customer_data(string $email): array {
        global $wpdb;

        $email = sanitize_email($email);
        if (empty($email)) {
            return ['success' => false, 'error' => __('Invalid email address.', 'peanut-license-server')];
        }

        $licenses_table = $wpdb->prefix . 'peanut_licenses';
        $activations_table = $wpdb->prefix . 'peanut_activations';

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Generate anonymous identifier
            $anon_id = 'anon_' . substr(md5($email . wp_salt()), 0, 12);
            $anon_email = $anon_id . '@anonymized.local';

            // Get license IDs before anonymization
            $license_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$licenses_table} WHERE customer_email = %s",
                $email
            ));

            if (empty($license_ids)) {
                $wpdb->query('ROLLBACK');
                return ['success' => false, 'error' => __('No data found for this email.', 'peanut-license-server')];
            }

            // Anonymize licenses
            $licenses_updated = $wpdb->update(
                $licenses_table,
                [
                    'customer_email' => $anon_email,
                    'customer_name' => 'Anonymized User',
                ],
                ['customer_email' => $email],
                ['%s', '%s'],
                ['%s']
            );

            // Anonymize activations (remove IP addresses)
            $license_ids_placeholder = implode(',', array_fill(0, count($license_ids), '%d'));
            $activations_updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$activations_table}
                 SET ip_address = '0.0.0.0',
                     site_url = CONCAT('https://', %s, '.anonymized.local'),
                     site_name = 'Anonymized Site'
                 WHERE license_id IN ({$license_ids_placeholder})",
                $anon_id,
                ...$license_ids
            ));

            $wpdb->query('COMMIT');

            // Log this action
            self::log_request($email, self::REQUEST_ANONYMIZE, self::STATUS_COMPLETED, [
                'anonymized_id' => $anon_id,
                'licenses_updated' => $licenses_updated,
                'activations_updated' => $activations_updated,
            ]);

            // Log to audit trail if available
            if (class_exists('Peanut_Audit_Trail')) {
                Peanut_Audit_Trail::log('gdpr_anonymize', [
                    'original_email' => $email,
                    'anonymized_id' => $anon_id,
                    'licenses_affected' => count($license_ids),
                ]);
            }

            return [
                'success' => true,
                'licenses_updated' => $licenses_updated ?: count($license_ids),
                'activations_updated' => $activations_updated ?: 0,
                'anonymized_id' => $anon_id,
            ];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete customer data completely (GDPR right to erasure)
     */
    public static function delete_customer_data(string $email): array {
        global $wpdb;

        $email = sanitize_email($email);
        if (empty($email)) {
            return ['success' => false, 'error' => __('Invalid email address.', 'peanut-license-server')];
        }

        $licenses_table = $wpdb->prefix . 'peanut_licenses';
        $activations_table = $wpdb->prefix . 'peanut_activations';
        $logs_table = $wpdb->prefix . 'peanut_update_logs';
        $validation_logs_table = $wpdb->prefix . 'peanut_validation_logs';
        $audit_table = $wpdb->prefix . 'peanut_audit_trail';
        $restrictions_table = $wpdb->prefix . 'peanut_license_restrictions';

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Get license IDs and key hashes before deletion
            $licenses = $wpdb->get_results($wpdb->prepare(
                "SELECT id, license_key_hash FROM {$licenses_table} WHERE customer_email = %s",
                $email
            ));

            if (empty($licenses)) {
                $wpdb->query('ROLLBACK');
                return ['success' => false, 'error' => __('No data found for this email.', 'peanut-license-server')];
            }

            $license_ids = wp_list_pluck($licenses, 'id');
            $license_hashes = wp_list_pluck($licenses, 'license_key_hash');
            $license_ids_placeholder = implode(',', array_fill(0, count($license_ids), '%d'));
            $license_hashes_placeholder = implode(',', array_fill(0, count($license_hashes), '%s'));

            // Count activations before deletion
            $activations_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$activations_table} WHERE license_id IN ({$license_ids_placeholder})",
                ...$license_ids
            ));

            // Delete activations
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$activations_table} WHERE license_id IN ({$license_ids_placeholder})",
                ...$license_ids
            ));

            // Delete update logs
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$logs_table} WHERE license_id IN ({$license_ids_placeholder})",
                ...$license_ids
            ));

            // Delete validation logs
            if ($wpdb->get_var("SHOW TABLES LIKE '{$validation_logs_table}'")) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$validation_logs_table} WHERE license_key_hash IN ({$license_hashes_placeholder})",
                    ...$license_hashes
                ));
            }

            // Delete security restrictions
            if ($wpdb->get_var("SHOW TABLES LIKE '{$restrictions_table}'")) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$restrictions_table} WHERE license_id IN ({$license_ids_placeholder})",
                    ...$license_ids
                ));
            }

            // Delete audit trail entries
            if ($wpdb->get_var("SHOW TABLES LIKE '{$audit_table}'")) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$audit_table} WHERE license_id IN ({$license_ids_placeholder})",
                    ...$license_ids
                ));
            }

            // Delete licenses
            $licenses_deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$licenses_table} WHERE customer_email = %s",
                $email
            ));

            $wpdb->query('COMMIT');

            // Log this action
            self::log_request($email, self::REQUEST_DELETE, self::STATUS_COMPLETED, [
                'licenses_deleted' => $licenses_deleted,
                'activations_deleted' => $activations_count,
            ]);

            return [
                'success' => true,
                'licenses_deleted' => $licenses_deleted,
                'activations_deleted' => (int) $activations_count,
            ];

        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Submit a GDPR request (for customer portal)
     */
    public static function submit_request(string $email, string $type, array $metadata = []): int {
        global $wpdb;

        $result = $wpdb->insert(
            self::get_table_name(),
            [
                'email' => sanitize_email($email),
                'request_type' => $type,
                'status' => self::STATUS_PENDING,
                'metadata' => wp_json_encode($metadata),
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );

        if ($result) {
            // Send notification to admin
            self::notify_admin_new_request($email, $type);
            return $wpdb->insert_id;
        }

        return 0;
    }

    /**
     * Get pending GDPR requests
     */
    public static function get_pending_requests(): array {
        global $wpdb;
        $table = self::get_table_name();

        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            return [];
        }

        return $wpdb->get_results(
            "SELECT * FROM {$table}
             WHERE status IN ('pending', 'processing')
             ORDER BY created_at ASC"
        );
    }

    /**
     * Get all requests with pagination
     */
    public static function get_requests(array $args = []): array {
        global $wpdb;
        $table = self::get_table_name();

        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            return ['data' => [], 'total' => 0, 'total_pages' => 0];
        }

        $page = $args['page'] ?? 1;
        $per_page = $args['per_page'] ?? 20;
        $status = $args['status'] ?? '';

        $where = '1=1';
        $params = [];

        if (!empty($status)) {
            $where .= ' AND status = %s';
            $params[] = $status;
        }

        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where}",
            ...$params
        ));

        $offset = ($page - 1) * $per_page;
        $params[] = $per_page;
        $params[] = $offset;

        $data = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE {$where}
             ORDER BY created_at DESC
             LIMIT %d OFFSET %d",
            ...$params
        ));

        return [
            'data' => $data,
            'total' => (int) $total,
            'total_pages' => ceil($total / $per_page),
        ];
    }

    /**
     * Process a pending request
     */
    public static function process_request(int $request_id): array {
        global $wpdb;
        $table = self::get_table_name();

        $request = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $request_id
        ));

        if (!$request) {
            return ['success' => false, 'error' => __('Request not found.', 'peanut-license-server')];
        }

        // Update status to processing
        $wpdb->update(
            $table,
            ['status' => self::STATUS_PROCESSING, 'processed_at' => current_time('mysql')],
            ['id' => $request_id],
            ['%s', '%s'],
            ['%d']
        );

        // Process based on type
        switch ($request->request_type) {
            case self::REQUEST_EXPORT:
                $result = self::export_customer_data($request->email);
                break;

            case self::REQUEST_ANONYMIZE:
                $result = self::anonymize_customer_data($request->email);
                break;

            case self::REQUEST_DELETE:
                $result = self::delete_customer_data($request->email);
                break;

            default:
                $result = ['success' => false, 'error' => __('Unknown request type.', 'peanut-license-server')];
        }

        // Update final status
        $final_status = (!empty($result) && (is_array($result) ? ($result['success'] ?? true) : true))
            ? self::STATUS_COMPLETED
            : self::STATUS_FAILED;

        $wpdb->update(
            $table,
            [
                'status' => $final_status,
                'completed_at' => current_time('mysql'),
                'result' => wp_json_encode($result),
            ],
            ['id' => $request_id],
            ['%s', '%s', '%s'],
            ['%d']
        );

        // Notify customer
        if ($final_status === self::STATUS_COMPLETED) {
            self::notify_customer_completed($request->email, $request->request_type);
        }

        return is_array($result) ? $result : ['success' => true, 'data' => $result];
    }

    /**
     * Log a GDPR request
     */
    private static function log_request(string $email, string $type, string $status, array $metadata = []): void {
        global $wpdb;
        $table = self::get_table_name();

        if (!$wpdb->get_var("SHOW TABLES LIKE '{$table}'")) {
            return;
        }

        $wpdb->insert(
            $table,
            [
                'email' => $email,
                'request_type' => $type,
                'status' => $status,
                'metadata' => wp_json_encode($metadata),
                'created_at' => current_time('mysql'),
                'completed_at' => current_time('mysql'),
                'processed_by' => get_current_user_id(),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%d']
        );
    }

    /**
     * Notify admin of new GDPR request
     */
    private static function notify_admin_new_request(string $email, string $type): void {
        $admin_email = get_option('admin_email');
        $subject = sprintf(
            __('[%s] New GDPR %s Request', 'peanut-license-server'),
            get_bloginfo('name'),
            ucfirst($type)
        );

        $message = sprintf(
            __("A new GDPR %s request has been submitted.\n\nCustomer Email: %s\nRequest Type: %s\n\nPlease review and process this request in the WordPress admin.", 'peanut-license-server'),
            $type,
            $email,
            $type
        );

        wp_mail($admin_email, $subject, $message);
    }

    /**
     * Notify customer that request is completed
     */
    private static function notify_customer_completed(string $email, string $type): void {
        $subject = sprintf(
            __('[%s] Your GDPR Request Has Been Processed', 'peanut-license-server'),
            get_bloginfo('name')
        );

        $type_labels = [
            self::REQUEST_EXPORT => __('data export', 'peanut-license-server'),
            self::REQUEST_ANONYMIZE => __('data anonymization', 'peanut-license-server'),
            self::REQUEST_DELETE => __('data deletion', 'peanut-license-server'),
        ];

        $message = sprintf(
            __("Your %s request has been processed.\n\nIf you have any questions, please contact us.", 'peanut-license-server'),
            $type_labels[$type] ?? $type
        );

        wp_mail($email, $subject, $message);
    }

    /**
     * Create GDPR requests table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            request_type ENUM('export', 'anonymize', 'delete') NOT NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            metadata TEXT DEFAULT NULL,
            result TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            completed_at DATETIME DEFAULT NULL,
            processed_by BIGINT UNSIGNED DEFAULT NULL,
            KEY idx_email (email),
            KEY idx_status (status),
            KEY idx_created_at (created_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Register WordPress privacy exporter
     */
    public static function register_privacy_exporter(array $exporters): array {
        $exporters['peanut-license-server'] = [
            'exporter_friendly_name' => __('Peanut License Server', 'peanut-license-server'),
            'callback' => [self::class, 'privacy_exporter_callback'],
        ];
        return $exporters;
    }

    /**
     * Privacy exporter callback for WordPress privacy tools
     */
    public static function privacy_exporter_callback(string $email, int $page = 1): array {
        $data = self::export_customer_data($email);

        if (!$data) {
            return [
                'data' => [],
                'done' => true,
            ];
        }

        $export_items = [];

        // Export licenses
        foreach ($data['licenses'] as $license) {
            $export_items[] = [
                'group_id' => 'peanut-licenses',
                'group_label' => __('Peanut Licenses', 'peanut-license-server'),
                'item_id' => 'license-' . $license->id,
                'data' => [
                    ['name' => __('License Key', 'peanut-license-server'), 'value' => $license->license_key],
                    ['name' => __('Tier', 'peanut-license-server'), 'value' => $license->tier],
                    ['name' => __('Status', 'peanut-license-server'), 'value' => $license->status],
                    ['name' => __('Created', 'peanut-license-server'), 'value' => $license->created_at],
                ],
            ];
        }

        // Export activations
        foreach ($data['activations'] as $activation) {
            $export_items[] = [
                'group_id' => 'peanut-activations',
                'group_label' => __('License Activations', 'peanut-license-server'),
                'item_id' => 'activation-' . $activation->license_id . '-' . md5($activation->site_url),
                'data' => [
                    ['name' => __('Site URL', 'peanut-license-server'), 'value' => $activation->site_url],
                    ['name' => __('Activated', 'peanut-license-server'), 'value' => $activation->activated_at],
                    ['name' => __('IP Address', 'peanut-license-server'), 'value' => $activation->ip_address],
                ],
            ];
        }

        return [
            'data' => $export_items,
            'done' => true,
        ];
    }

    /**
     * Register WordPress privacy eraser
     */
    public static function register_privacy_eraser(array $erasers): array {
        $erasers['peanut-license-server'] = [
            'eraser_friendly_name' => __('Peanut License Server', 'peanut-license-server'),
            'callback' => [self::class, 'privacy_eraser_callback'],
        ];
        return $erasers;
    }

    /**
     * Privacy eraser callback for WordPress privacy tools
     */
    public static function privacy_eraser_callback(string $email, int $page = 1): array {
        $result = self::anonymize_customer_data($email);

        return [
            'items_removed' => $result['success'] ? ($result['licenses_updated'] ?? 0) : 0,
            'items_retained' => false,
            'messages' => $result['success']
                ? [__('License data has been anonymized.', 'peanut-license-server')]
                : [__('No license data found for this email.', 'peanut-license-server')],
            'done' => true,
        ];
    }
}

// Register with WordPress privacy tools
add_filter('wp_privacy_personal_data_exporters', ['Peanut_GDPR_Compliance', 'register_privacy_exporter']);
add_filter('wp_privacy_personal_data_erasers', ['Peanut_GDPR_Compliance', 'register_privacy_eraser']);
