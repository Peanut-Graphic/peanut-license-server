<?php
/**
 * License Manager Class
 *
 * Handles all license CRUD operations.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_License_Manager {

    /**
     * Default tier configuration (fallback)
     */
    public const TIERS = [
        'free' => [
            'name' => 'Free',
            'max_activations' => 1,
            'features' => ['basic'],
        ],
        'pro' => [
            'name' => 'Pro',
            'max_activations' => 3,
            'features' => ['basic', 'pro'],
        ],
        'agency' => [
            'name' => 'Agency',
            'max_activations' => 25,
            'features' => ['basic', 'pro', 'agency'],
        ],
    ];

    /**
     * Per-product tier configurations
     * Each product can have its own feature sets per tier
     */
    public const PRODUCT_TIERS = [
        'peanut-suite' => [
            'free' => [
                'name' => 'Free',
                'max_activations' => 1,
                'features' => ['utm', 'links', 'contacts', 'dashboard'],
            ],
            'pro' => [
                'name' => 'Pro',
                'max_activations' => 3,
                'features' => ['utm', 'links', 'contacts', 'dashboard', 'popups', 'analytics', 'export'],
            ],
            'agency' => [
                'name' => 'Agency',
                'max_activations' => 25,
                'features' => ['utm', 'links', 'contacts', 'dashboard', 'popups', 'analytics', 'export', 'monitor', 'white_label', 'priority_support'],
            ],
        ],
        'formflow' => [
            'free' => [
                'name' => 'Free',
                'max_activations' => 1,
                'features' => ['basic_forms', 'email_notifications', 'spam_protection'],
            ],
            'pro' => [
                'name' => 'Pro',
                'max_activations' => 3,
                'features' => ['basic_forms', 'email_notifications', 'spam_protection', 'conditional_logic', 'file_uploads', 'multi_step', 'integrations', 'analytics'],
            ],
            'agency' => [
                'name' => 'Agency',
                'max_activations' => 25,
                'features' => ['basic_forms', 'email_notifications', 'spam_protection', 'conditional_logic', 'file_uploads', 'multi_step', 'integrations', 'analytics', 'white_label', 'priority_support', 'custom_templates'],
            ],
        ],
        'peanut-booker' => [
            'free' => [
                'name' => 'Free',
                'max_activations' => 1,
                'features' => ['basic_booking', 'email_notifications', 'calendar_view'],
            ],
            'pro' => [
                'name' => 'Pro',
                'max_activations' => 3,
                'features' => ['basic_booking', 'email_notifications', 'calendar_view', 'payments', 'reminders', 'google_calendar', 'zoom_integration', 'custom_fields'],
            ],
            'agency' => [
                'name' => 'Agency',
                'max_activations' => 25,
                'features' => ['basic_booking', 'email_notifications', 'calendar_view', 'payments', 'reminders', 'google_calendar', 'zoom_integration', 'custom_fields', 'multi_staff', 'white_label', 'priority_support', 'api_access'],
            ],
        ],
    ];

    /**
     * Get licenses table name
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_licenses';
    }

    /**
     * Get activations table name
     */
    public static function get_activations_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_activations';
    }

    /**
     * Generate a unique license key
     */
    public static function generate_license_key(): string {
        $segments = [];
        for ($i = 0; $i < 4; $i++) {
            $segments[] = strtoupper(substr(md5(wp_generate_uuid4()), 0, 4));
        }
        return implode('-', $segments);
    }

    /**
     * Hash a license key
     */
    public static function hash_license_key(string $key): string {
        return hash('sha256', $key);
    }

    /**
     * Create a new license
     */
    public static function create(array $data): ?object {
        global $wpdb;

        $license_key = self::generate_license_key();
        $tier = $data['tier'] ?? 'free';
        $tier_config = self::TIERS[$tier] ?? self::TIERS['free'];

        $insert_data = [
            'license_key' => $license_key,
            'license_key_hash' => self::hash_license_key($license_key),
            'order_id' => $data['order_id'] ?? null,
            'subscription_id' => $data['subscription_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'customer_email' => sanitize_email($data['customer_email']),
            'customer_name' => sanitize_text_field($data['customer_name'] ?? ''),
            'product_id' => absint($data['product_id']),
            'tier' => $tier,
            'status' => 'active',
            'max_activations' => $data['max_activations'] ?? $tier_config['max_activations'],
            'expires_at' => $data['expires_at'] ?? null,
        ];

        $result = $wpdb->insert(
            self::get_table_name(),
            $insert_data,
            ['%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s']
        );

        if ($result === false) {
            Peanut_Logger::error('Failed to create license', ['db_error' => $wpdb->last_error]);
            return null;
        }

        $license_id = $wpdb->insert_id;
        return self::get_by_id($license_id);
    }

    /**
     * Get license by ID
     */
    public static function get_by_id(int $id): ?object {
        global $wpdb;
        $table = self::get_table_name();

        $license = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id)
        );

        if ($license) {
            $license->activations = self::get_activations($license->id);
            $license->activations_count = count(array_filter($license->activations, fn($a) => $a->is_active));
        }

        return $license;
    }

    /**
     * Get license by key
     */
    public static function get_by_key(string $key): ?object {
        global $wpdb;
        $table = self::get_table_name();

        $license = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE license_key = %s OR license_key_hash = %s",
                $key,
                self::hash_license_key($key)
            )
        );

        if ($license) {
            $license->activations = self::get_activations($license->id);
            $license->activations_count = count(array_filter($license->activations, fn($a) => $a->is_active));
        }

        return $license;
    }

    /**
     * Get licenses by user ID
     */
    public static function get_user_licenses(int $user_id): array {
        global $wpdb;
        $table = self::get_table_name();

        $licenses = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE user_id = %d ORDER BY created_at DESC",
                $user_id
            )
        );

        foreach ($licenses as $license) {
            $license->activations = self::get_activations($license->id);
            $license->activations_count = count(array_filter($license->activations, fn($a) => $a->is_active));
        }

        return $licenses;
    }

    /**
     * Get licenses by email
     */
    public static function get_by_email(string $email): array {
        global $wpdb;
        $table = self::get_table_name();

        $licenses = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE customer_email = %s ORDER BY created_at DESC",
                sanitize_email($email)
            )
        );

        foreach ($licenses as $license) {
            $license->activations = self::get_activations($license->id);
            $license->activations_count = count(array_filter($license->activations, fn($a) => $a->is_active));
        }

        return $licenses;
    }

    /**
     * Get all licenses with pagination
     */
    public static function get_all(array $args = []): array {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'status' => '',
            'tier' => '',
            'search' => '',
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

        if (!empty($args['tier'])) {
            $where_clauses[] = 'tier = %s';
            $where_values[] = $args['tier'];
        }

        if (!empty($args['search'])) {
            $where_clauses[] = '(license_key LIKE %s OR customer_email LIKE %s OR customer_name LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search;
            $where_values[] = $search;
            $where_values[] = $search;
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
        $licenses = $wpdb->get_results($wpdb->prepare($sql, ...$values));

        foreach ($licenses as $license) {
            $license->activations = self::get_activations($license->id);
            $license->activations_count = count(array_filter($license->activations, fn($a) => $a->is_active));
        }

        return [
            'data' => $licenses,
            'total' => $total,
            'page' => $args['page'],
            'per_page' => $args['per_page'],
            'total_pages' => ceil($total / $args['per_page']),
        ];
    }

    /**
     * Update license
     */
    public static function update(int $id, array $data): bool {
        global $wpdb;

        $allowed_fields = ['status', 'tier', 'max_activations', 'expires_at', 'customer_email', 'customer_name'];
        $update_data = [];
        $formats = [];

        foreach ($allowed_fields as $field) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $formats[] = in_array($field, ['max_activations']) ? '%d' : '%s';
            }
        }

        if (empty($update_data)) {
            return false;
        }

        $result = $wpdb->update(
            self::get_table_name(),
            $update_data,
            ['id' => $id],
            $formats,
            ['%d']
        );

        // Invalidate cache on successful update
        if ($result !== false) {
            Peanut_License_Validator::invalidate_cache_by_id($id);
        }

        return $result !== false;
    }

    /**
     * Delete license
     */
    public static function delete(int $id): bool {
        global $wpdb;

        // Invalidate cache before deletion
        Peanut_License_Validator::invalidate_cache_by_id($id);

        // Delete activations first
        $wpdb->delete(self::get_activations_table(), ['license_id' => $id], ['%d']);

        // Delete license
        $result = $wpdb->delete(self::get_table_name(), ['id' => $id], ['%d']);

        return $result !== false;
    }

    /**
     * Revoke license
     */
    public static function revoke(int $id): bool {
        return self::update($id, ['status' => 'revoked']);
    }

    /**
     * Suspend license
     */
    public static function suspend(int $id): bool {
        return self::update($id, ['status' => 'suspended']);
    }

    /**
     * Reactivate license
     */
    public static function reactivate(int $id): bool {
        return self::update($id, ['status' => 'active']);
    }

    /**
     * Regenerate license key
     * Creates a new key while preserving all other license data
     */
    public static function regenerate_key(int $id): ?object {
        global $wpdb;

        $license = self::get_by_id($id);
        if (!$license) {
            return null;
        }

        // Generate new key
        $new_key = self::generate_license_key();
        $new_hash = self::hash_license_key($new_key);

        // Update the license
        $result = $wpdb->update(
            self::get_table_name(),
            [
                'license_key' => $new_key,
                'license_key_hash' => $new_hash,
            ],
            ['id' => $id],
            ['%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            Peanut_Logger::error('Failed to regenerate license key', [
                'license_id' => $id,
                'db_error' => $wpdb->last_error,
            ]);
            return null;
        }

        // Log the regeneration
        do_action('peanut_license_key_regenerated', $id, $license->license_key, $new_key);

        return self::get_by_id($id);
    }

    /**
     * Transfer license to new email/user
     */
    public static function transfer_license(int $id, array $new_owner): ?object {
        global $wpdb;

        $license = self::get_by_id($id);
        if (!$license) {
            return null;
        }

        $update_data = [];
        $formats = [];

        if (!empty($new_owner['email'])) {
            $update_data['customer_email'] = sanitize_email($new_owner['email']);
            $formats[] = '%s';
        }

        if (!empty($new_owner['name'])) {
            $update_data['customer_name'] = sanitize_text_field($new_owner['name']);
            $formats[] = '%s';
        }

        if (isset($new_owner['user_id'])) {
            $update_data['user_id'] = absint($new_owner['user_id']);
            $formats[] = '%d';
        }

        if (empty($update_data)) {
            return $license;
        }

        $result = $wpdb->update(
            self::get_table_name(),
            $update_data,
            ['id' => $id],
            $formats,
            ['%d']
        );

        if ($result === false) {
            Peanut_Logger::error('Failed to transfer license', [
                'license_id' => $id,
                'db_error' => $wpdb->last_error,
            ]);
            return null;
        }

        // Optionally deactivate all sites on transfer
        if (!empty($new_owner['deactivate_sites'])) {
            self::deactivate_all_sites($id);
        }

        // Log the transfer
        do_action('peanut_license_transferred', $id, $license, $new_owner);

        return self::get_by_id($id);
    }

    /**
     * Deactivate all sites for a license
     */
    public static function deactivate_all_sites(int $license_id): int {
        global $wpdb;

        $result = $wpdb->update(
            self::get_activations_table(),
            [
                'is_active' => 0,
                'deactivated_at' => current_time('mysql'),
            ],
            ['license_id' => $license_id],
            ['%d', '%s'],
            ['%d']
        );

        return $result !== false ? $result : 0;
    }

    /**
     * Resend license email
     */
    public static function resend_license_email(int $id): bool {
        $license = self::get_by_id($id);
        if (!$license) {
            return false;
        }

        $sent = self::send_license_email($license);

        if ($sent) {
            do_action('peanut_license_email_resent', $id, $license->customer_email);
        }

        return $sent;
    }

    /**
     * Get license activations
     */
    public static function get_activations(int $license_id): array {
        global $wpdb;
        $table = self::get_activations_table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE license_id = %d ORDER BY activated_at DESC",
                $license_id
            )
        );
    }

    /**
     * Get activation by site URL for a license.
     * Uses direct database query instead of iterating through all activations.
     *
     * @param int $license_id License ID.
     * @param string $site_url Site URL to check.
     * @param bool $active_only Only return active activations.
     * @return object|null Activation record or null.
     */
    public static function get_activation_by_site(int $license_id, string $site_url, bool $active_only = true): ?object {
        global $wpdb;

        $site_hash = md5(untrailingslashit(esc_url_raw($site_url)));

        $sql = "SELECT * FROM " . self::get_activations_table() . " WHERE license_id = %d AND site_hash = %s";

        if ($active_only) {
            $sql .= " AND is_active = 1";
        }

        return $wpdb->get_row($wpdb->prepare($sql, $license_id, $site_hash));
    }

    /**
     * Count active activations for a license using database query.
     *
     * @param int $license_id License ID.
     * @return int Number of active activations.
     */
    public static function count_active_activations(int $license_id): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM " . self::get_activations_table() . " WHERE license_id = %d AND is_active = 1",
            $license_id
        ));
    }

    /**
     * Add site activation
     */
    public static function add_activation(int $license_id, array $data): ?object {
        global $wpdb;

        $site_url = untrailingslashit(esc_url_raw($data['site_url']));
        $site_hash = md5($site_url);

        // Check if already activated
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_activations_table() . " WHERE license_id = %d AND site_hash = %s",
                $license_id,
                $site_hash
            )
        );

        if ($existing) {
            // Reactivate if inactive
            if (!$existing->is_active) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE " . self::get_activations_table() . "
                    SET is_active = 1, last_checked = %s, plugin_version = %s, deactivated_at = NULL
                    WHERE id = %d",
                    current_time('mysql'),
                    $data['plugin_version'] ?? null,
                    $existing->id
                ));
            } else {
                // Update last checked
                $wpdb->update(
                    self::get_activations_table(),
                    [
                        'last_checked' => current_time('mysql'),
                        'plugin_version' => $data['plugin_version'] ?? null,
                    ],
                    ['id' => $existing->id],
                    ['%s', '%s'],
                    ['%d']
                );
            }
            return $wpdb->get_row(
                $wpdb->prepare("SELECT * FROM " . self::get_activations_table() . " WHERE id = %d", $existing->id)
            );
        }

        // Create new activation
        $result = $wpdb->insert(
            self::get_activations_table(),
            [
                'license_id' => $license_id,
                'site_url' => $site_url,
                'site_name' => sanitize_text_field($data['site_name'] ?? ''),
                'site_hash' => $site_hash,
                'ip_address' => self::get_client_ip(),
                'plugin_version' => $data['plugin_version'] ?? null,
                'last_checked' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            return null;
        }

        // Invalidate cache when activation added
        Peanut_License_Validator::invalidate_cache_by_id($license_id);

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::get_activations_table() . " WHERE id = %d", $wpdb->insert_id)
        );
    }

    /**
     * Remove site activation
     */
    public static function remove_activation(int $license_id, string $site_url): bool {
        global $wpdb;

        $site_hash = md5(untrailingslashit(esc_url_raw($site_url)));

        $result = $wpdb->update(
            self::get_activations_table(),
            [
                'is_active' => 0,
                'deactivated_at' => current_time('mysql'),
            ],
            [
                'license_id' => $license_id,
                'site_hash' => $site_hash,
            ],
            ['%d', '%s'],
            ['%d', '%s']
        );

        // Invalidate cache when activation removed
        if ($result !== false) {
            Peanut_License_Validator::invalidate_cache_by_id($license_id);
        }

        return $result !== false;
    }

    /**
     * Deactivate site by activation ID
     */
    public static function deactivate_site(int $activation_id): bool {
        global $wpdb;

        // Get license ID before deactivating
        $activation = $wpdb->get_row($wpdb->prepare(
            "SELECT license_id FROM " . self::get_activations_table() . " WHERE id = %d",
            $activation_id
        ));

        $result = $wpdb->update(
            self::get_activations_table(),
            [
                'is_active' => 0,
                'deactivated_at' => current_time('mysql'),
            ],
            ['id' => $activation_id],
            ['%d', '%s'],
            ['%d']
        );

        // Invalidate cache when site deactivated
        if ($result !== false && $activation) {
            Peanut_License_Validator::invalidate_cache_by_id($activation->license_id);
        }

        return $result !== false;
    }

    /**
     * Check if license can activate more sites
     */
    public static function can_activate(object $license): bool {
        $active_count = count(array_filter($license->activations ?? [], fn($a) => $a->is_active));
        return $active_count < $license->max_activations;
    }

    /**
     * Check if license is valid
     */
    public static function is_valid(object $license): bool {
        if ($license->status !== 'active') {
            return false;
        }

        if ($license->expires_at && strtotime($license->expires_at) < time()) {
            return false;
        }

        return true;
    }

    /**
     * Get features for tier (optionally product-specific)
     */
    public static function get_tier_features(string $tier, ?string $product = null): array {
        // If product specified and has custom tiers, use those
        if ($product && isset(self::PRODUCT_TIERS[$product][$tier])) {
            return self::PRODUCT_TIERS[$product][$tier]['features'];
        }

        // Fall back to default tiers
        return self::TIERS[$tier]['features'] ?? self::TIERS['free']['features'];
    }

    /**
     * Get tier configuration for a specific product
     */
    public static function get_product_tier(string $product, string $tier): array {
        if (isset(self::PRODUCT_TIERS[$product][$tier])) {
            return self::PRODUCT_TIERS[$product][$tier];
        }

        return self::TIERS[$tier] ?? self::TIERS['free'];
    }

    /**
     * Get all tiers for a product
     */
    public static function get_product_tiers(string $product): array {
        return self::PRODUCT_TIERS[$product] ?? self::TIERS;
    }

    /**
     * Check if a feature is available for a tier/product combination
     */
    public static function has_feature(string $feature, string $tier, ?string $product = null): bool {
        $features = self::get_tier_features($tier, $product);
        return in_array($feature, $features, true);
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
     * Send license email
     */
    public static function send_license_email(object $license, $order = null): bool {
        $to = $license->customer_email;
        $subject = sprintf(
            __('[%s] Your Peanut Suite License Key', 'peanut-license-server'),
            get_bloginfo('name')
        );

        $tier_name = self::TIERS[$license->tier]['name'] ?? 'Free';

        $message = sprintf(
            __("Hello %s,\n\nThank you for purchasing Peanut Suite %s!\n\nYour license key is:\n%s\n\nYou can activate this license on up to %d site(s).\n\nTo activate:\n1. Install the Peanut Suite plugin on your WordPress site\n2. Go to Peanut Suite > Settings\n3. Enter your license key and click Activate\n\nDownload the plugin: %s\n\nManage your license: %s\n\nIf you have any questions, please contact us at %s\n\nBest regards,\nThe Peanut Graphic Team", 'peanut-license-server'),
            $license->customer_name ?: 'there',
            $tier_name,
            $license->license_key,
            $license->max_activations,
            home_url('/downloads/peanut-suite/'),
            home_url('/my-account/licenses/'),
            get_option('admin_email')
        );

        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        ];

        return wp_mail($to, $subject, $message, $headers);
    }

    /**
     * Get license statistics
     */
    public static function get_statistics(): array {
        global $wpdb;
        $table = self::get_table_name();

        $stats = [
            'total' => 0,
            'active' => 0,
            'expired' => 0,
            'suspended' => 0,
            'revoked' => 0,
            'by_tier' => [
                'free' => 0,
                'pro' => 0,
                'agency' => 0,
            ],
            'activations_total' => 0,
        ];

        // Status counts
        $status_counts = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$table} GROUP BY status"
        );

        foreach ($status_counts as $row) {
            $stats[$row->status] = (int) $row->count;
            $stats['total'] += (int) $row->count;
        }

        // Tier counts
        $tier_counts = $wpdb->get_results(
            "SELECT tier, COUNT(*) as count FROM {$table} WHERE status = 'active' GROUP BY tier"
        );

        foreach ($tier_counts as $row) {
            $stats['by_tier'][$row->tier] = (int) $row->count;
        }

        // Activations count
        $activations_table = self::get_activations_table();
        $stats['activations_total'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$activations_table} WHERE is_active = 1"
        );

        return $stats;
    }

    /**
     * Process expired licenses
     */
    public static function process_expired_licenses(): int {
        global $wpdb;
        $table = self::get_table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->query(
            "UPDATE {$table} SET status = 'expired' WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < NOW()"
        );

        return $result !== false ? $result : 0;
    }
}
