<?php
/**
 * Audit Trail Class
 *
 * Tracks all license changes with full history and user attribution.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_Audit_Trail {

    /**
     * Event types
     */
    public const EVENT_LICENSE_CREATED = 'license_created';
    public const EVENT_LICENSE_UPDATED = 'license_updated';
    public const EVENT_LICENSE_DELETED = 'license_deleted';
    public const EVENT_LICENSE_ACTIVATED = 'license_activated';
    public const EVENT_LICENSE_DEACTIVATED = 'license_deactivated';
    public const EVENT_LICENSE_SUSPENDED = 'license_suspended';
    public const EVENT_LICENSE_REVOKED = 'license_revoked';
    public const EVENT_LICENSE_REACTIVATED = 'license_reactivated';
    public const EVENT_LICENSE_EXPIRED = 'license_expired';
    public const EVENT_LICENSE_RENEWED = 'license_renewed';
    public const EVENT_LICENSE_TRANSFERRED = 'license_transferred';
    public const EVENT_LICENSE_KEY_REGENERATED = 'key_regenerated';
    public const EVENT_SITE_ACTIVATED = 'site_activated';
    public const EVENT_SITE_DEACTIVATED = 'site_deactivated';
    public const EVENT_SETTINGS_CHANGED = 'settings_changed';
    public const EVENT_BULK_OPERATION = 'bulk_operation';
    public const EVENT_API_ACCESS = 'api_access';
    public const EVENT_EXPORT = 'data_exported';
    public const EVENT_IMPORT = 'data_imported';

    // Additional event types used by admin dashboard
    public const EVENT_STATUS_CHANGED = 'status_changed';
    public const EVENT_LICENSE_VALIDATED = 'license_validated';
    public const EVENT_VALIDATION_FAILED = 'validation_failed';
    public const EVENT_KEY_REGENERATED = 'key_regenerated';
    public const EVENT_TIER_CHANGED = 'tier_changed';
    public const EVENT_EXPIRY_CHANGED = 'expiry_changed';

    /**
     * Get table name
     */
    public static function get_table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_audit_trail';
    }

    /**
     * Log an event
     */
    public static function log(string $event, array $data = []): int {
        global $wpdb;

        $user_id = get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : null;

        $log_data = [
            'event_type' => $event,
            'user_id' => $user_id ?: null,
            'user_email' => $user ? $user->user_email : null,
            'user_name' => $user ? $user->display_name : 'System',
            'license_id' => $data['license_id'] ?? null,
            'object_type' => $data['object_type'] ?? 'license',
            'object_id' => $data['object_id'] ?? ($data['license_id'] ?? null),
            'action' => $data['action'] ?? self::get_action_from_event($event),
            'old_value' => isset($data['old_value']) ? wp_json_encode($data['old_value']) : null,
            'new_value' => isset($data['new_value']) ? wp_json_encode($data['new_value']) : null,
            'changes' => isset($data['changes']) ? wp_json_encode($data['changes']) : null,
            'ip_address' => self::get_client_ip(),
            'user_agent' => substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            'context' => isset($data['context']) ? wp_json_encode($data['context']) : null,
            'created_at' => current_time('mysql'),
        ];

        $wpdb->insert(
            self::get_table_name(),
            $log_data,
            ['%s', '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return $wpdb->insert_id;
    }

    /**
     * Log license creation
     */
    public static function log_license_created(object $license): int {
        return self::log(self::EVENT_LICENSE_CREATED, [
            'license_id' => $license->id,
            'new_value' => [
                'customer_email' => $license->customer_email,
                'tier' => $license->tier,
                'status' => $license->status,
                'max_activations' => $license->max_activations,
            ],
        ]);
    }

    /**
     * Log license update
     */
    public static function log_license_updated(int $license_id, array $old_values, array $new_values): int {
        $changes = [];
        foreach ($new_values as $key => $value) {
            if (isset($old_values[$key]) && $old_values[$key] !== $value) {
                $changes[$key] = [
                    'from' => $old_values[$key],
                    'to' => $value,
                ];
            }
        }

        return self::log(self::EVENT_LICENSE_UPDATED, [
            'license_id' => $license_id,
            'old_value' => $old_values,
            'new_value' => $new_values,
            'changes' => $changes,
        ]);
    }

    /**
     * Log license status change
     */
    public static function log_status_change(int $license_id, string $old_status, string $new_status, ?string $event = null): int {
        $event = $event ?: self::EVENT_LICENSE_UPDATED;

        // Map status changes to specific events
        $status_events = [
            'suspended' => self::EVENT_LICENSE_SUSPENDED,
            'revoked' => self::EVENT_LICENSE_REVOKED,
            'expired' => self::EVENT_LICENSE_EXPIRED,
        ];

        if ($new_status === 'active' && in_array($old_status, ['suspended', 'revoked', 'expired'])) {
            $event = self::EVENT_LICENSE_REACTIVATED;
        } elseif (isset($status_events[$new_status])) {
            $event = $status_events[$new_status];
        }

        return self::log($event, [
            'license_id' => $license_id,
            'changes' => [
                'status' => [
                    'from' => $old_status,
                    'to' => $new_status,
                ],
            ],
        ]);
    }

    /**
     * Log site activation
     */
    public static function log_site_activated(int $license_id, string $site_url, int $activation_id): int {
        return self::log(self::EVENT_SITE_ACTIVATED, [
            'license_id' => $license_id,
            'object_type' => 'activation',
            'object_id' => $activation_id,
            'new_value' => [
                'site_url' => $site_url,
            ],
        ]);
    }

    /**
     * Log site deactivation
     */
    public static function log_site_deactivated(int $license_id, string $site_url, int $activation_id): int {
        return self::log(self::EVENT_SITE_DEACTIVATED, [
            'license_id' => $license_id,
            'object_type' => 'activation',
            'object_id' => $activation_id,
            'old_value' => [
                'site_url' => $site_url,
            ],
        ]);
    }

    /**
     * Log license transfer
     */
    public static function log_transfer(int $license_id, array $old_owner, array $new_owner): int {
        return self::log(self::EVENT_LICENSE_TRANSFERRED, [
            'license_id' => $license_id,
            'old_value' => $old_owner,
            'new_value' => $new_owner,
            'changes' => [
                'customer_email' => [
                    'from' => $old_owner['email'] ?? null,
                    'to' => $new_owner['email'] ?? null,
                ],
            ],
        ]);
    }

    /**
     * Log key regeneration
     */
    public static function log_key_regenerated(int $license_id, string $old_key_masked, string $new_key_masked): int {
        return self::log(self::EVENT_LICENSE_KEY_REGENERATED, [
            'license_id' => $license_id,
            'changes' => [
                'license_key' => [
                    'from' => $old_key_masked,
                    'to' => $new_key_masked,
                ],
            ],
        ]);
    }

    /**
     * Log bulk operation
     */
    public static function log_bulk_operation(string $operation, int $count, array $ids = []): int {
        return self::log(self::EVENT_BULK_OPERATION, [
            'object_type' => 'bulk',
            'action' => $operation,
            'new_value' => [
                'operation' => $operation,
                'count' => $count,
                'affected_ids' => $ids,
            ],
        ]);
    }

    /**
     * Log export
     */
    public static function log_export(string $format, int $record_count): int {
        return self::log(self::EVENT_EXPORT, [
            'object_type' => 'export',
            'action' => 'export_' . $format,
            'new_value' => [
                'format' => $format,
                'record_count' => $record_count,
            ],
        ]);
    }

    /**
     * Log import
     */
    public static function log_import(string $format, array $results): int {
        return self::log(self::EVENT_IMPORT, [
            'object_type' => 'import',
            'action' => 'import_' . $format,
            'new_value' => $results,
        ]);
    }

    /**
     * Get audit logs with filtering
     */
    public static function get_logs(array $args = []): array {
        global $wpdb;
        $table = self::get_table_name();

        $defaults = [
            'page' => 1,
            'per_page' => 50,
            'license_id' => null,
            'user_id' => null,
            'event_type' => '',
            'object_type' => '',
            'date_from' => '',
            'date_to' => '',
            'search' => '',
            'orderby' => 'created_at',
            'order' => 'DESC',
        ];

        $args = wp_parse_args($args, $defaults);
        $offset = ($args['page'] - 1) * $args['per_page'];

        $where_clauses = ['1=1'];
        $where_values = [];

        if ($args['license_id']) {
            $where_clauses[] = 'license_id = %d';
            $where_values[] = $args['license_id'];
        }

        if ($args['user_id']) {
            $where_clauses[] = 'user_id = %d';
            $where_values[] = $args['user_id'];
        }

        if (!empty($args['event_type'])) {
            $where_clauses[] = 'event_type = %s';
            $where_values[] = $args['event_type'];
        }

        if (!empty($args['object_type'])) {
            $where_clauses[] = 'object_type = %s';
            $where_values[] = $args['object_type'];
        }

        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }

        if (!empty($args['search'])) {
            $where_clauses[] = '(user_email LIKE %s OR user_name LIKE %s OR ip_address LIKE %s)';
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
        $logs = $wpdb->get_results($wpdb->prepare($sql, ...$values));

        // Decode JSON fields
        foreach ($logs as $log) {
            $log->old_value = $log->old_value ? json_decode($log->old_value, true) : null;
            $log->new_value = $log->new_value ? json_decode($log->new_value, true) : null;
            $log->changes = $log->changes ? json_decode($log->changes, true) : null;
            $log->context = $log->context ? json_decode($log->context, true) : null;
        }

        return [
            'data' => $logs,
            'total' => $total,
            'page' => $args['page'],
            'per_page' => $args['per_page'],
            'total_pages' => ceil($total / $args['per_page']),
        ];
    }

    /**
     * Get logs for a specific license
     */
    public static function get_license_history(int $license_id): array {
        return self::get_logs([
            'license_id' => $license_id,
            'per_page' => 100,
        ]);
    }

    /**
     * Get activity by user
     */
    public static function get_user_activity(int $user_id, int $days = 30): array {
        $date_from = date('Y-m-d', strtotime("-{$days} days"));

        return self::get_logs([
            'user_id' => $user_id,
            'date_from' => $date_from,
            'per_page' => 100,
        ]);
    }

    /**
     * Get event type statistics
     */
    public static function get_event_statistics(int $days = 30): array {
        global $wpdb;
        $table = self::get_table_name();

        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT event_type, COUNT(*) as count
            FROM {$table}
            WHERE created_at >= %s
            GROUP BY event_type
            ORDER BY count DESC
        ", $since));

        $stats = [];
        foreach ($results as $row) {
            $stats[$row->event_type] = (int) $row->count;
        }

        return $stats;
    }

    /**
     * Cleanup old audit logs
     */
    public static function cleanup(int $days = 365): int {
        global $wpdb;
        $table = self::get_table_name();

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s",
            $cutoff
        ));

        return $result !== false ? $result : 0;
    }

    /**
     * Create audit trail table
     */
    public static function create_table(): void {
        global $wpdb;
        $table = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(64) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            user_email VARCHAR(255) DEFAULT NULL,
            user_name VARCHAR(255) DEFAULT NULL,
            license_id BIGINT UNSIGNED DEFAULT NULL,
            object_type VARCHAR(32) DEFAULT 'license',
            object_id BIGINT UNSIGNED DEFAULT NULL,
            action VARCHAR(64) DEFAULT NULL,
            old_value LONGTEXT DEFAULT NULL,
            new_value LONGTEXT DEFAULT NULL,
            changes LONGTEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            context TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_event_type (event_type),
            KEY idx_user_id (user_id),
            KEY idx_license_id (license_id),
            KEY idx_object (object_type, object_id),
            KEY idx_created_at (created_at),
            KEY idx_ip_address (ip_address)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get action from event type
     */
    private static function get_action_from_event(string $event): string {
        $parts = explode('.', $event);
        return $parts[1] ?? $parts[0];
    }

    /**
     * Get client IP
     */
    private static function get_client_ip(): string {
        // Delegate to the shared trusted-proxy resolver so forwarded headers
        // (CF-Connecting-IP / X-Forwarded-For / X-Real-IP) are only honoured
        // behind a configured trusted proxy and cannot be spoofed to defeat
        // rate-limits / IP bans. See includes/client-ip-functions.php.
        return peanut_get_client_ip();
    }

    /**
     * Get human-readable event description
     */
    public static function get_event_description(object $log): string {
        $descriptions = [
            self::EVENT_LICENSE_CREATED => __('License created', 'peanut-license-server'),
            self::EVENT_LICENSE_UPDATED => __('License updated', 'peanut-license-server'),
            self::EVENT_LICENSE_DELETED => __('License deleted', 'peanut-license-server'),
            self::EVENT_LICENSE_SUSPENDED => __('License suspended', 'peanut-license-server'),
            self::EVENT_LICENSE_REVOKED => __('License revoked', 'peanut-license-server'),
            self::EVENT_LICENSE_REACTIVATED => __('License reactivated', 'peanut-license-server'),
            self::EVENT_LICENSE_EXPIRED => __('License expired', 'peanut-license-server'),
            self::EVENT_LICENSE_RENEWED => __('License renewed', 'peanut-license-server'),
            self::EVENT_LICENSE_TRANSFERRED => __('License transferred', 'peanut-license-server'),
            self::EVENT_LICENSE_KEY_REGENERATED => __('License key regenerated', 'peanut-license-server'),
            self::EVENT_SITE_ACTIVATED => __('Site activated', 'peanut-license-server'),
            self::EVENT_SITE_DEACTIVATED => __('Site deactivated', 'peanut-license-server'),
            self::EVENT_BULK_OPERATION => __('Bulk operation performed', 'peanut-license-server'),
            self::EVENT_EXPORT => __('Data exported', 'peanut-license-server'),
            self::EVENT_IMPORT => __('Data imported', 'peanut-license-server'),
        ];

        return $descriptions[$log->event_type] ?? $log->event_type;
    }

    /**
     * Get event icon
     */
    public static function get_event_icon(string $event_type): string {
        $icons = [
            self::EVENT_LICENSE_CREATED => 'plus-alt',
            self::EVENT_LICENSE_UPDATED => 'edit',
            self::EVENT_LICENSE_DELETED => 'trash',
            self::EVENT_LICENSE_SUSPENDED => 'hidden',
            self::EVENT_LICENSE_REVOKED => 'dismiss',
            self::EVENT_LICENSE_REACTIVATED => 'yes-alt',
            self::EVENT_LICENSE_EXPIRED => 'clock',
            self::EVENT_LICENSE_RENEWED => 'update',
            self::EVENT_LICENSE_TRANSFERRED => 'randomize',
            self::EVENT_LICENSE_KEY_REGENERATED => 'admin-network',
            self::EVENT_SITE_ACTIVATED => 'admin-site',
            self::EVENT_SITE_DEACTIVATED => 'admin-site-alt3',
            self::EVENT_BULK_OPERATION => 'forms',
            self::EVENT_EXPORT => 'download',
            self::EVENT_IMPORT => 'upload',
        ];

        return $icons[$event_type] ?? 'marker';
    }

    /**
     * Get event color
     */
    public static function get_event_color(string $event_type): string {
        $colors = [
            self::EVENT_LICENSE_CREATED => '#10b981',
            self::EVENT_LICENSE_UPDATED => '#3b82f6',
            self::EVENT_LICENSE_DELETED => '#ef4444',
            self::EVENT_LICENSE_SUSPENDED => '#f59e0b',
            self::EVENT_LICENSE_REVOKED => '#ef4444',
            self::EVENT_LICENSE_REACTIVATED => '#10b981',
            self::EVENT_LICENSE_EXPIRED => '#f59e0b',
            self::EVENT_LICENSE_RENEWED => '#10b981',
            self::EVENT_LICENSE_TRANSFERRED => '#8b5cf6',
            self::EVENT_LICENSE_KEY_REGENERATED => '#3b82f6',
            self::EVENT_SITE_ACTIVATED => '#10b981',
            self::EVENT_SITE_DEACTIVATED => '#64748b',
        ];

        return $colors[$event_type] ?? '#64748b';
    }
}
