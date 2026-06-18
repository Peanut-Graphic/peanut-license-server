<?php
/**
 * WooCommerce Subscription Sync Class
 *
 * Automatically syncs license status with WooCommerce Subscriptions.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_Subscription_Sync {

    /**
     * Max subscriptions synced per scheduled run. The daily cron walks the
     * fleet in chunks of this size, advancing a cursor across runs, so one run
     * never does unbounded work. Lower = gentler per run / more runs to cover
     * the fleet; cron is daily so a large fleet still wraps within days.
     */
    const SYNC_CHUNK_SIZE = 100;

    /** Option holding the OFFSET into the subscription list for the next run. */
    const SYNC_CURSOR_OPTION = 'peanut_subscription_sync_cursor';

    /**
     * Test seam: when set, called instead of the real sync_subscription() so
     * the unit suite can assert chunking/cursor behaviour without WooCommerce
     * or network I/O. Production leaves this null.
     *
     * @var callable|null
     */
    public static $test_sync_subscription = null;

    /**
     * Initialize subscription sync
     */
    public static function init(): void {
        if (!class_exists('WC_Subscriptions')) {
            return;
        }

        // Subscription status changes
        add_action('woocommerce_subscription_status_active', [self::class, 'handle_subscription_active']);
        add_action('woocommerce_subscription_status_on-hold', [self::class, 'handle_subscription_on_hold']);
        add_action('woocommerce_subscription_status_cancelled', [self::class, 'handle_subscription_cancelled']);
        add_action('woocommerce_subscription_status_expired', [self::class, 'handle_subscription_expired']);
        add_action('woocommerce_subscription_status_pending-cancel', [self::class, 'handle_subscription_pending_cancel']);

        // Renewal processed
        add_action('woocommerce_subscription_renewal_payment_complete', [self::class, 'handle_renewal_complete']);
        add_action('woocommerce_subscription_renewal_payment_failed', [self::class, 'handle_renewal_failed']);

        // Date changes
        add_action('woocommerce_subscription_date_updated', [self::class, 'handle_date_updated'], 10, 3);

        // Product switching (upgrades/downgrades)
        add_action('woocommerce_subscription_item_switched', [self::class, 'handle_item_switched'], 10, 4);

        // Manual sync via admin
        add_action('wp_ajax_peanut_sync_subscription', [self::class, 'ajax_sync_subscription']);

        // Cron for periodic sync
        add_action('peanut_subscription_sync', [self::class, 'run_scheduled_sync']);
        if (!wp_next_scheduled('peanut_subscription_sync')) {
            wp_schedule_event(time(), 'daily', 'peanut_subscription_sync');
        }
    }

    /**
     * Handle subscription activated
     */
    public static function handle_subscription_active($subscription): void {
        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;
        $licenses = self::get_licenses_for_subscription($subscription_id);

        foreach ($licenses as $license) {
            if ($license->status !== 'active') {
                Peanut_License_Manager::reactivate($license->id);

                if (class_exists('Peanut_Audit_Trail')) {
                    Peanut_Audit_Trail::log('subscription_sync', [
                        'license_id' => $license->id,
                        'subscription_id' => $subscription_id,
                        'action' => 'activated',
                        'old_status' => $license->status,
                        'new_status' => 'active',
                    ]);
                }
            }
        }

        // Trigger webhook
        if (class_exists('Peanut_Webhook_Notifications')) {
            Peanut_Webhook_Notifications::send('subscription.activated', [
                'subscription_id' => $subscription_id,
                'licenses' => wp_list_pluck($licenses, 'id'),
            ]);
        }
    }

    /**
     * Handle subscription on hold
     */
    public static function handle_subscription_on_hold($subscription): void {
        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;
        $licenses = self::get_licenses_for_subscription($subscription_id);

        foreach ($licenses as $license) {
            if ($license->status === 'active') {
                Peanut_License_Manager::suspend($license->id);

                if (class_exists('Peanut_Audit_Trail')) {
                    Peanut_Audit_Trail::log('subscription_sync', [
                        'license_id' => $license->id,
                        'subscription_id' => $subscription_id,
                        'action' => 'suspended',
                        'reason' => 'subscription_on_hold',
                    ]);
                }
            }
        }
    }

    /**
     * Handle subscription cancelled
     */
    public static function handle_subscription_cancelled($subscription): void {
        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;
        $licenses = self::get_licenses_for_subscription($subscription_id);

        foreach ($licenses as $license) {
            // Revoke the license
            Peanut_License_Manager::update($license->id, ['status' => 'revoked']);

            if (class_exists('Peanut_Audit_Trail')) {
                Peanut_Audit_Trail::log('subscription_sync', [
                    'license_id' => $license->id,
                    'subscription_id' => $subscription_id,
                    'action' => 'revoked',
                    'reason' => 'subscription_cancelled',
                ]);
            }
        }

        // Trigger webhook
        if (class_exists('Peanut_Webhook_Notifications')) {
            Peanut_Webhook_Notifications::send('subscription.cancelled', [
                'subscription_id' => $subscription_id,
                'licenses' => wp_list_pluck($licenses, 'id'),
            ]);
        }
    }

    /**
     * Handle subscription expired
     */
    public static function handle_subscription_expired($subscription): void {
        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;
        $licenses = self::get_licenses_for_subscription($subscription_id);

        foreach ($licenses as $license) {
            Peanut_License_Manager::update($license->id, ['status' => 'expired']);

            if (class_exists('Peanut_Audit_Trail')) {
                Peanut_Audit_Trail::log('subscription_sync', [
                    'license_id' => $license->id,
                    'subscription_id' => $subscription_id,
                    'action' => 'expired',
                    'reason' => 'subscription_expired',
                ]);
            }
        }

        // Trigger webhook
        if (class_exists('Peanut_Webhook_Notifications')) {
            Peanut_Webhook_Notifications::send('subscription.expired', [
                'subscription_id' => $subscription_id,
                'licenses' => wp_list_pluck($licenses, 'id'),
            ]);
        }
    }

    /**
     * Handle subscription pending cancel
     */
    public static function handle_subscription_pending_cancel($subscription): void {
        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;

        // Send warning email
        $licenses = self::get_licenses_for_subscription($subscription_id);
        foreach ($licenses as $license) {
            self::send_pending_cancel_notification($license, $subscription);
        }
    }

    /**
     * Handle renewal payment complete
     */
    public static function handle_renewal_complete($subscription): void {
        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;
        $subscription_obj = wcs_get_subscription($subscription_id);

        if (!$subscription_obj) {
            return;
        }

        $licenses = self::get_licenses_for_subscription($subscription_id);
        $next_payment = $subscription_obj->get_date('next_payment');

        foreach ($licenses as $license) {
            // Reactivate if needed
            if ($license->status !== 'active') {
                Peanut_License_Manager::reactivate($license->id);
            }

            // Update expiry to next payment date
            if ($next_payment) {
                Peanut_License_Manager::update($license->id, [
                    'expires_at' => $next_payment,
                ]);
            }

            if (class_exists('Peanut_Audit_Trail')) {
                Peanut_Audit_Trail::log('subscription_sync', [
                    'license_id' => $license->id,
                    'subscription_id' => $subscription_id,
                    'action' => 'renewed',
                    'new_expiry' => $next_payment,
                ]);
            }
        }

        // Trigger webhook
        if (class_exists('Peanut_Webhook_Notifications')) {
            Peanut_Webhook_Notifications::send('subscription.renewed', [
                'subscription_id' => $subscription_id,
                'licenses' => wp_list_pluck($licenses, 'id'),
                'next_payment' => $next_payment,
            ]);
        }
    }

    /**
     * Handle renewal payment failed
     */
    public static function handle_renewal_failed($subscription): void {
        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;
        $licenses = self::get_licenses_for_subscription($subscription_id);

        foreach ($licenses as $license) {
            // Send payment failed notification
            self::send_payment_failed_notification($license, $subscription);

            if (class_exists('Peanut_Audit_Trail')) {
                Peanut_Audit_Trail::log('subscription_sync', [
                    'license_id' => $license->id,
                    'subscription_id' => $subscription_id,
                    'action' => 'renewal_failed',
                ]);
            }
        }
    }

    /**
     * Handle subscription date updated
     */
    public static function handle_date_updated($subscription, $date_type, $datetime): void {
        if ($date_type !== 'next_payment' && $date_type !== 'end') {
            return;
        }

        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;
        $licenses = self::get_licenses_for_subscription($subscription_id);

        foreach ($licenses as $license) {
            if ($date_type === 'next_payment' || $date_type === 'end') {
                Peanut_License_Manager::update($license->id, [
                    'expires_at' => $datetime,
                ]);

                if (class_exists('Peanut_Audit_Trail')) {
                    Peanut_Audit_Trail::log('subscription_sync', [
                        'license_id' => $license->id,
                        'subscription_id' => $subscription_id,
                        'action' => 'expiry_updated',
                        'date_type' => $date_type,
                        'new_date' => $datetime,
                    ]);
                }
            }
        }
    }

    /**
     * Handle subscription product switched (upgrade/downgrade)
     */
    public static function handle_item_switched($subscription, $new_item, $old_item, $next_payment_timestamp): void {
        $subscription_id = is_object($subscription) ? $subscription->get_id() : $subscription;
        $licenses = self::get_licenses_for_subscription($subscription_id);

        $new_product_id = $new_item->get_product_id();
        $new_tier = self::get_tier_for_product($new_product_id);

        foreach ($licenses as $license) {
            $old_tier = $license->tier;

            // Update tier
            Peanut_License_Manager::update($license->id, [
                'tier' => $new_tier,
                'product_id' => $new_product_id,
            ]);

            if (class_exists('Peanut_Audit_Trail')) {
                Peanut_Audit_Trail::log('tier_changed', [
                    'license_id' => $license->id,
                    'subscription_id' => $subscription_id,
                    'old_tier' => $old_tier,
                    'new_tier' => $new_tier,
                    'reason' => 'subscription_switched',
                ]);
            }
        }

        // Trigger webhook
        if (class_exists('Peanut_Webhook_Notifications')) {
            Peanut_Webhook_Notifications::send('license.tier_changed', [
                'subscription_id' => $subscription_id,
                'licenses' => wp_list_pluck($licenses, 'id'),
                'old_tier' => $old_tier ?? 'unknown',
                'new_tier' => $new_tier,
            ]);
        }
    }

    /**
     * Get licenses for a subscription
     */
    public static function get_licenses_for_subscription(int $subscription_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}peanut_licenses WHERE subscription_id = %d",
            $subscription_id
        ));
    }

    /**
     * Get tier for a product
     */
    private static function get_tier_for_product(int $product_id): string {
        // Check product meta for tier mapping
        $tier = get_post_meta($product_id, '_peanut_license_tier', true);

        if (!empty($tier)) {
            return $tier;
        }

        // Check product slug/name for tier hints
        $product = wc_get_product($product_id);
        if (!$product) {
            return 'pro';
        }

        $slug = $product->get_slug() ?? '';
        $name = strtolower($product->get_name() ?? '');

        if (!empty($slug) && strpos($slug, 'agency') !== false) {
            return 'agency';
        }
        if (!empty($name) && strpos($name, 'agency') !== false) {
            return 'agency';
        }

        if (!empty($slug) && strpos($slug, 'free') !== false) {
            return 'free';
        }
        if (!empty($name) && strpos($name, 'free') !== false) {
            return 'free';
        }

        return 'pro';
    }

    /**
     * Sync subscription to license
     */
    public static function sync_subscription(int $subscription_id): array {
        $subscription = wcs_get_subscription($subscription_id);

        if (!$subscription) {
            return ['success' => false, 'error' => __('Subscription not found.', 'peanut-license-server')];
        }

        $licenses = self::get_licenses_for_subscription($subscription_id);

        if (empty($licenses)) {
            return ['success' => false, 'error' => __('No licenses found for this subscription.', 'peanut-license-server')];
        }

        $status = $subscription->get_status();
        $next_payment = $subscription->get_date('next_payment');
        $end_date = $subscription->get_date('end');

        $synced = 0;

        foreach ($licenses as $license) {
            $updates = [];

            // Sync status
            $new_status = self::map_subscription_status($status);
            if ($license->status !== $new_status) {
                $updates['status'] = $new_status;
            }

            // Sync expiry
            $expiry = $next_payment ?: $end_date;
            if ($expiry && $license->expires_at !== $expiry) {
                $updates['expires_at'] = $expiry;
            }

            if (!empty($updates)) {
                Peanut_License_Manager::update($license->id, $updates);
                $synced++;

                if (class_exists('Peanut_Audit_Trail')) {
                    Peanut_Audit_Trail::log('subscription_sync', [
                        'license_id' => $license->id,
                        'subscription_id' => $subscription_id,
                        'action' => 'manual_sync',
                        'updates' => $updates,
                    ]);
                }
            }
        }

        return [
            'success' => true,
            'synced' => $synced,
            'total' => count($licenses),
        ];
    }

    /**
     * Map subscription status to license status
     */
    private static function map_subscription_status(string $status): string {
        $map = [
            'active' => 'active',
            'on-hold' => 'suspended',
            'pending-cancel' => 'active',
            'cancelled' => 'revoked',
            'expired' => 'expired',
            'pending' => 'suspended',
        ];

        return $map[$status] ?? 'suspended';
    }

    /**
     * AJAX handler for manual sync
     */
    public static function ajax_sync_subscription(): void {
        check_ajax_referer('peanut_subscription_sync', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'peanut-license-server')]);
        }

        $subscription_id = intval($_POST['subscription_id'] ?? 0);

        if (!$subscription_id) {
            wp_send_json_error(['message' => __('Invalid subscription ID.', 'peanut-license-server')]);
        }

        $result = self::sync_subscription($subscription_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Run scheduled sync for a bounded chunk of subscriptions.
     *
     * Previously this SELECTed every subscription_id and looped over all of
     * them with no LIMIT — unbounded work that doesn't scale as the fleet
     * grows. It now processes at most SYNC_CHUNK_SIZE per run, ordered
     * deterministically, advancing a persisted cursor across runs and wrapping
     * back to the start once the whole fleet is covered. A large fleet is fully
     * synced over several daily runs instead of one giant pass.
     */
    public static function run_scheduled_sync(): void {
        global $wpdb;

        $cursor = max(0, (int) get_option(self::SYNC_CURSOR_OPTION, 0));
        $limit = self::SYNC_CHUNK_SIZE;

        // Deterministic ORDER BY so OFFSET paging is stable across runs.
        $subscription_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT subscription_id FROM {$wpdb->prefix}peanut_licenses
             WHERE subscription_id IS NOT NULL AND subscription_id > 0
             ORDER BY subscription_id ASC
             LIMIT %d OFFSET %d",
            $limit,
            $cursor
        ));

        $synced = 0;
        $errors = 0;

        foreach ($subscription_ids as $subscription_id) {
            $result = self::dispatch_sync((int) $subscription_id);
            if (!empty($result['success'])) {
                $synced += (int) ($result['synced'] ?? 0);
            } else {
                $errors++;
            }
        }

        // Advance the cursor. If this page came back short (or empty), we've
        // reached the end of the fleet — wrap back to the start for next run.
        $processed = count($subscription_ids);
        $next_cursor = ($processed < $limit) ? 0 : $cursor + $processed;
        update_option(self::SYNC_CURSOR_OPTION, $next_cursor);

        // Log the sync
        Peanut_Logger::info('Subscription sync chunk completed', [
            'licenses_synced' => $synced,
            'errors' => $errors,
            'chunk_size' => $limit,
            'chunk_offset' => $cursor,
            'processed' => $processed,
            'next_offset' => $next_cursor,
        ]);
    }

    /**
     * Invoke sync_subscription() (or the test seam).
     *
     * @return array{success:bool,synced?:int}
     */
    private static function dispatch_sync(int $subscription_id): array {
        if (is_callable(self::$test_sync_subscription)) {
            return call_user_func(self::$test_sync_subscription, $subscription_id);
        }
        return self::sync_subscription($subscription_id);
    }

    /**
     * Send pending cancel notification
     */
    private static function send_pending_cancel_notification($license, $subscription): void {
        $subject = sprintf(
            __('[%s] Your subscription is pending cancellation', 'peanut-license-server'),
            get_bloginfo('name')
        );

        $message = sprintf(
            __("Your subscription for license %s is scheduled for cancellation.\n\nThe license will remain active until the end of the current billing period.\n\nIf you'd like to continue using the product, you can reactivate your subscription from your account.", 'peanut-license-server'),
            $license->license_key
        );

        wp_mail($license->customer_email, $subject, $message);
    }

    /**
     * Send payment failed notification
     */
    private static function send_payment_failed_notification($license, $subscription): void {
        $subject = sprintf(
            __('[%s] Payment failed for your subscription', 'peanut-license-server'),
            get_bloginfo('name')
        );

        $message = sprintf(
            __("We were unable to process the renewal payment for your license %s.\n\nPlease update your payment method to keep your license active.\n\nYou can update your payment method from your account.", 'peanut-license-server'),
            $license->license_key
        );

        wp_mail($license->customer_email, $subject, $message);
    }

    /**
     * Link license to subscription
     */
    public static function link_license_to_subscription(int $license_id, int $subscription_id): bool {
        return (bool) Peanut_License_Manager::update($license_id, [
            'subscription_id' => $subscription_id,
        ]);
    }

    /**
     * Unlink license from subscription
     */
    public static function unlink_license_from_subscription(int $license_id): bool {
        return (bool) Peanut_License_Manager::update($license_id, [
            'subscription_id' => null,
        ]);
    }
}

// Initialize
add_action('plugins_loaded', ['Peanut_Subscription_Sync', 'init'], 20);
