<?php
/**
 * Webhook Handler Class
 *
 * Handles WooCommerce order and subscription events.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_Webhook_Handler {

    /**
     * Product meta key for tier
     */
    private const TIER_META_KEY = '_peanut_tier';

    /**
     * Process WooCommerce order completed
     */
    public function process_order_completed(int $order_id): void {
        $order = wc_get_order($order_id);

        if (!$order) {
            Peanut_Logger::warning('Order not found', ['order_id' => $order_id]);
            return;
        }

        // Check if license already generated for this order
        $existing_license = $this->get_license_by_order($order_id);
        if ($existing_license) {
            Peanut_Logger::debug('License already exists for order', ['order_id' => $order_id]);
            return;
        }

        // Process each item in the order
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $tier = $this->get_product_tier($product);
            if (!$tier) {
                continue; // Not a Peanut Suite product
            }

            $this->create_license_from_order($order, $product, $tier);
        }
    }

    /**
     * Process subscription activated
     */
    public function process_subscription_active($subscription): void {
        if (!function_exists('wcs_get_subscription')) {
            return;
        }

        $subscription_id = $subscription->get_id();
        $order_id = $subscription->get_parent_id();

        // Get license by subscription
        $license = $this->get_license_by_subscription($subscription_id);

        if ($license) {
            // Reactivate existing license
            if ($license->status !== 'active') {
                Peanut_License_Manager::reactivate($license->id);

                // Update expiration
                $next_payment = $subscription->get_date('next_payment');
                if ($next_payment) {
                    Peanut_License_Manager::update($license->id, [
                        'expires_at' => $next_payment,
                    ]);
                }
            }
        } else {
            // Create new license for subscription
            $order = wc_get_order($order_id);
            if ($order) {
                foreach ($subscription->get_items() as $item) {
                    $product = $item->get_product();
                    if (!$product) {
                        continue;
                    }

                    $tier = $this->get_product_tier($product);
                    if (!$tier) {
                        continue;
                    }

                    $this->create_license_from_subscription($subscription, $order, $product, $tier);
                }
            }
        }
    }

    /**
     * Process subscription expired
     */
    public function process_subscription_expired($subscription): void {
        $subscription_id = $subscription->get_id();
        $license = $this->get_license_by_subscription($subscription_id);

        if ($license) {
            Peanut_License_Manager::update($license->id, ['status' => 'expired']);

            // Send expiration email
            $this->send_expiration_email($license);
        }
    }

    /**
     * Process subscription cancelled
     */
    public function process_subscription_cancelled($subscription): void {
        $subscription_id = $subscription->get_id();
        $license = $this->get_license_by_subscription($subscription_id);

        if ($license) {
            // Keep as expired, not revoked (they paid for the period)
            Peanut_License_Manager::update($license->id, ['status' => 'expired']);

            // Send cancellation email
            $this->send_cancellation_email($license);
        }
    }

    /**
     * Create license from order
     */
    private function create_license_from_order($order, $product, string $tier): ?object {
        $tier_config = Peanut_License_Manager::TIERS[$tier] ?? Peanut_License_Manager::TIERS['free'];

        // Calculate expiration (1 year from now)
        $expires_at = date('Y-m-d H:i:s', strtotime('+1 year'));

        $license = Peanut_License_Manager::create([
            'order_id' => $order->get_id(),
            'user_id' => $order->get_user_id(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_formatted_billing_full_name(),
            'product_id' => $product->get_id(),
            'tier' => $tier,
            'max_activations' => $tier_config['max_activations'],
            'expires_at' => $expires_at,
        ]);

        if ($license) {
            // Add order note
            $order->add_order_note(sprintf(
                __('Peanut Suite license generated: %s (%s tier)', 'peanut-license-server'),
                $license->license_key,
                ucfirst($tier)
            ));

            // Send license email
            Peanut_License_Manager::send_license_email($license, $order);
        }

        return $license;
    }

    /**
     * Create license from subscription
     */
    private function create_license_from_subscription($subscription, $order, $product, string $tier): ?object {
        $tier_config = Peanut_License_Manager::TIERS[$tier] ?? Peanut_License_Manager::TIERS['free'];

        // Get next payment date as expiration
        $next_payment = $subscription->get_date('next_payment');
        $expires_at = $next_payment ?: date('Y-m-d H:i:s', strtotime('+1 year'));

        $license = Peanut_License_Manager::create([
            'order_id' => $order->get_id(),
            'subscription_id' => $subscription->get_id(),
            'user_id' => $order->get_user_id(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_formatted_billing_full_name(),
            'product_id' => $product->get_id(),
            'tier' => $tier,
            'max_activations' => $tier_config['max_activations'],
            'expires_at' => $expires_at,
        ]);

        if ($license) {
            // Add subscription note
            $subscription->add_order_note(sprintf(
                __('Peanut Suite license generated: %s (%s tier)', 'peanut-license-server'),
                $license->license_key,
                ucfirst($tier)
            ));

            // Send license email
            Peanut_License_Manager::send_license_email($license, $order);
        }

        return $license;
    }

    /**
     * Get product tier from meta
     */
    private function get_product_tier($product): ?string {
        $tier = $product->get_meta(self::TIER_META_KEY);

        if (!$tier || !isset(Peanut_License_Manager::TIERS[$tier])) {
            // Check by SKU. Match whole words only: a bare strpos() for 'pro'
            // false-matches the substring inside "product", so any SKU/name
            // containing "product" (extremely common) would be mis-tiered as Pro.
            $sku = strtolower($product->get_sku() ?? '');
            if (!empty($sku)) {
                if (preg_match('/\bagency\b/', $sku)) {
                    return 'agency';
                }
                if (preg_match('/\bpro\b/', $sku)) {
                    return 'pro';
                }
            }

            // Check by product name (whole words only, same reasoning as above).
            $name = strtolower($product->get_name() ?? '');
            if (!empty($name)) {
                if (preg_match('/\bagency\b/', $name)) {
                    return 'agency';
                }
                if (preg_match('/\bpro\b/', $name)) {
                    return 'pro';
                }
            }

            return null;
        }

        return $tier;
    }

    /**
     * Get license by order ID
     */
    private function get_license_by_order(int $order_id): ?object {
        global $wpdb;
        $table = Peanut_License_Manager::get_table_name();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d", $order_id)
        );
    }

    /**
     * Get license by subscription ID
     */
    private function get_license_by_subscription(int $subscription_id): ?object {
        global $wpdb;
        $table = Peanut_License_Manager::get_table_name();

        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE subscription_id = %d", $subscription_id)
        );
    }

    /**
     * Send expiration email
     */
    private function send_expiration_email(object $license): void {
        $to = $license->customer_email;
        $subject = sprintf(
            __('[%s] Your Peanut Suite License Has Expired', 'peanut-license-server'),
            get_bloginfo('name')
        );

        $message = sprintf(
            __("Hello %s,\n\nYour Peanut Suite %s license has expired.\n\nLicense Key: %s\n\nYour premium features have been disabled, but the free features will continue to work.\n\nTo restore premium features, please renew your license at:\n%s\n\nIf you have any questions, please contact us at %s\n\nBest regards,\nThe Peanut Graphic Team", 'peanut-license-server'),
            $license->customer_name ?: 'there',
            Peanut_License_Manager::TIERS[$license->tier]['name'] ?? 'Pro',
            $license->license_key,
            home_url('/my-account/subscriptions/'),
            get_option('admin_email')
        );

        wp_mail($to, $subject, $message);
    }

    /**
     * Send cancellation email
     */
    private function send_cancellation_email(object $license): void {
        $to = $license->customer_email;
        $subject = sprintf(
            __('[%s] Your Peanut Suite Subscription Has Been Cancelled', 'peanut-license-server'),
            get_bloginfo('name')
        );

        $message = sprintf(
            __("Hello %s,\n\nYour Peanut Suite subscription has been cancelled.\n\nLicense Key: %s\n\nYour premium features will remain active until your current billing period ends on %s.\n\nAfter that date, your account will revert to the free tier.\n\nIf you'd like to resubscribe, visit:\n%s\n\nWe'd love to have you back! If there's anything we could have done better, please let us know at %s\n\nBest regards,\nThe Peanut Graphic Team", 'peanut-license-server'),
            $license->customer_name ?: 'there',
            $license->license_key,
            date_i18n(get_option('date_format'), strtotime($license->expires_at)),
            home_url('/peanut-suite/pricing/'),
            get_option('admin_email')
        );

        wp_mail($to, $subject, $message);
    }

    /**
     * Add product meta box for tier selection
     */
    public static function add_product_meta_box(): void {
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'render_tier_field']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_tier_field']);
    }

    /**
     * Render tier selection field
     */
    public static function render_tier_field(): void {
        global $post;

        echo '<div class="options_group">';

        woocommerce_wp_select([
            'id' => self::TIER_META_KEY,
            'label' => __('Peanut Suite Tier', 'peanut-license-server'),
            'description' => __('Select the license tier for this product.', 'peanut-license-server'),
            'desc_tip' => true,
            'options' => [
                '' => __('Not a Peanut Suite product', 'peanut-license-server'),
                'pro' => __('Pro (3 sites)', 'peanut-license-server'),
                'agency' => __('Agency (25 sites)', 'peanut-license-server'),
            ],
        ]);

        echo '</div>';
    }

    /**
     * Save tier field
     */
    public static function save_tier_field(int $post_id): void {
        $tier = isset($_POST[self::TIER_META_KEY]) ? sanitize_text_field($_POST[self::TIER_META_KEY]) : '';
        update_post_meta($post_id, self::TIER_META_KEY, $tier);
    }
}

// Register product meta box
add_action('admin_init', ['Peanut_Webhook_Handler', 'add_product_meta_box']);
