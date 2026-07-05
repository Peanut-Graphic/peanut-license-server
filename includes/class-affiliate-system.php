<?php
/**
 * Affiliate/Reseller System Class
 *
 * Manages affiliate codes, referral tracking, and commissions.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_Affiliate_System {

    /**
     * Commission types
     */
    public const COMMISSION_PERCENTAGE = 'percentage';
    public const COMMISSION_FIXED = 'fixed';

    /**
     * Payout statuses
     */
    public const PAYOUT_PENDING = 'pending';
    public const PAYOUT_PROCESSING = 'processing';
    public const PAYOUT_COMPLETED = 'completed';
    public const PAYOUT_FAILED = 'failed';

    /**
     * Get affiliates table name
     */
    public static function get_affiliates_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_affiliates';
    }

    /**
     * Get referrals table name
     */
    public static function get_referrals_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_referrals';
    }

    /**
     * Get payouts table name
     */
    public static function get_payouts_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'peanut_affiliate_payouts';
    }

    /**
     * Initialize affiliate system
     */
    public static function init(): void {
        // Track referrals on order completed
        add_action('woocommerce_order_status_completed', [self::class, 'track_order_referral'], 20);

        // Add affiliate code to cookie
        add_action('init', [self::class, 'track_affiliate_visit']);

        // Admin AJAX handlers
        add_action('wp_ajax_peanut_affiliate_payout', [self::class, 'ajax_process_payout']);

        // Shortcode for affiliate dashboard
        add_shortcode('peanut_affiliate_dashboard', [self::class, 'render_affiliate_dashboard']);
    }

    /**
     * Create a new affiliate
     */
    public static function create_affiliate(array $data): ?int {
        global $wpdb;

        // Generate unique code
        $code = $data['code'] ?? self::generate_affiliate_code($data['name'] ?? '');

        // Check if code exists
        if (self::get_affiliate_by_code($code)) {
            $code = self::generate_affiliate_code($data['name'] ?? '', true);
        }

        $result = $wpdb->insert(
            self::get_affiliates_table(),
            [
                'user_id' => $data['user_id'] ?? null,
                'name' => sanitize_text_field($data['name']),
                'email' => sanitize_email($data['email']),
                'code' => $code,
                'commission_type' => $data['commission_type'] ?? self::COMMISSION_PERCENTAGE,
                'commission_rate' => floatval($data['commission_rate'] ?? 20),
                'payment_email' => sanitize_email($data['payment_email'] ?? $data['email']),
                'payment_method' => sanitize_text_field($data['payment_method'] ?? 'paypal'),
                'is_active' => 1,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%s']
        );

        if (!$result) {
            return null;
        }

        $affiliate_id = $wpdb->insert_id;

        // Send welcome email
        self::send_welcome_email($affiliate_id);

        return $affiliate_id;
    }

    /**
     * Generate affiliate code
     */
    private static function generate_affiliate_code(string $name = '', bool $with_random = false): string {
        if (!empty($name)) {
            $code = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 6));
        } else {
            $code = '';
        }

        if ($with_random || strlen($code) < 4) {
            $code .= strtoupper(wp_generate_password(6, false));
        }

        return substr($code, 0, 10);
    }

    /**
     * Update affiliate
     */
    public static function update_affiliate(int $affiliate_id, array $data): bool {
        global $wpdb;

        $update_data = [];
        $format = [];

        $fields = [
            'name' => '%s',
            'email' => '%s',
            'commission_type' => '%s',
            'commission_rate' => '%f',
            'payment_email' => '%s',
            'payment_method' => '%s',
            'is_active' => '%d',
        ];

        foreach ($fields as $field => $field_format) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $format[] = $field_format;
            }
        }

        if (empty($update_data)) {
            return true;
        }

        $update_data['updated_at'] = current_time('mysql');
        $format[] = '%s';

        return $wpdb->update(
            self::get_affiliates_table(),
            $update_data,
            ['id' => $affiliate_id],
            $format,
            ['%d']
        ) !== false;
    }

    /**
     * Get affiliate by ID
     */
    public static function get_affiliate(int $affiliate_id): ?object {
        global $wpdb;

        $affiliate = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_affiliates_table() . " WHERE id = %d",
            $affiliate_id
        ));

        if ($affiliate) {
            $affiliate->stats = self::get_affiliate_stats($affiliate_id);
        }

        return $affiliate;
    }

    /**
     * Get affiliate by code
     */
    public static function get_affiliate_by_code(string $code): ?object {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_affiliates_table() . " WHERE code = %s",
            strtoupper($code)
        ));
    }

    /**
     * Get affiliate by user ID
     */
    public static function get_affiliate_by_user(int $user_id): ?object {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_affiliates_table() . " WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get all affiliates
     */
    public static function get_affiliates(array $args = []): array {
        global $wpdb;
        $table = self::get_affiliates_table();

        $page = $args['page'] ?? 1;
        $per_page = $args['per_page'] ?? 20;
        $is_active = $args['is_active'] ?? null;

        $where = '1=1';
        $params = [];

        if ($is_active !== null) {
            $where .= ' AND is_active = %d';
            $params[] = $is_active ? 1 : 0;
        }

        $offset = ($page - 1) * $per_page;
        $params[] = $per_page;
        $params[] = $offset;

        $affiliates = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
            ...$params
        ));

        foreach ($affiliates as $affiliate) {
            $affiliate->stats = self::get_affiliate_stats($affiliate->id);
        }

        return $affiliates;
    }

    /**
     * Get affiliate stats
     */
    public static function get_affiliate_stats(int $affiliate_id): object {
        global $wpdb;
        $referrals_table = self::get_referrals_table();
        $payouts_table = self::get_payouts_table();

        $stats = new stdClass();

        // Total referrals
        $stats->total_referrals = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referrals_table} WHERE affiliate_id = %d",
            $affiliate_id
        ));

        // Successful referrals (with order)
        $stats->successful_referrals = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$referrals_table} WHERE affiliate_id = %d AND order_id IS NOT NULL",
            $affiliate_id
        ));

        // Total earnings
        $stats->total_earnings = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(commission_amount), 0) FROM {$referrals_table} WHERE affiliate_id = %d AND order_id IS NOT NULL",
            $affiliate_id
        ));

        // Pending earnings (not yet paid)
        $stats->pending_earnings = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(commission_amount), 0) FROM {$referrals_table} WHERE affiliate_id = %d AND is_paid = 0",
            $affiliate_id
        ));

        // Total paid
        $stats->total_paid = (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$payouts_table} WHERE affiliate_id = %d AND status = 'completed'",
            $affiliate_id
        ));

        // Conversion rate
        $stats->conversion_rate = $stats->total_referrals > 0
            ? round(($stats->successful_referrals / $stats->total_referrals) * 100, 2)
            : 0;

        return $stats;
    }

    /**
     * Track affiliate visit
     */
    public static function track_affiliate_visit(): void {
        if (!isset($_GET['ref']) && !isset($_GET['affiliate'])) {
            return;
        }

        $code = sanitize_text_field($_GET['ref'] ?? $_GET['affiliate']);
        $affiliate = self::get_affiliate_by_code($code);

        if (!$affiliate || !$affiliate->is_active) {
            return;
        }

        // Set cookie for 30 days
        $cookie_name = 'peanut_affiliate';
        $cookie_value = $affiliate->id . '|' . $code;
        $expiry = time() + (30 * 24 * 60 * 60);

        setcookie($cookie_name, $cookie_value, $expiry, '/');

        // Track the visit
        self::record_visit($affiliate->id);
    }

    /**
     * Record a visit
     */
    private static function record_visit(int $affiliate_id): void {
        global $wpdb;

        $wpdb->insert(
            self::get_referrals_table(),
            [
                'affiliate_id' => $affiliate_id,
                'visitor_ip' => self::get_visitor_ip(),
                'referrer_url' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : null,
                'landing_page' => esc_url_raw(home_url($_SERVER['REQUEST_URI'] ?? '/')),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Track order referral
     */
    public static function track_order_referral(int $order_id): void {
        // Check for affiliate cookie
        if (!isset($_COOKIE['peanut_affiliate'])) {
            return;
        }

        $cookie_parts = explode('|', sanitize_text_field($_COOKIE['peanut_affiliate']));
        $affiliate_id = intval($cookie_parts[0] ?? 0);

        if (!$affiliate_id) {
            return;
        }

        $affiliate = self::get_affiliate($affiliate_id);

        if (!$affiliate || !$affiliate->is_active) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Check if already tracked
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM " . self::get_referrals_table() . " WHERE order_id = %d",
            $order_id
        ));

        if ($existing) {
            return;
        }

        // Calculate commission
        $order_total = $order->get_total();
        $commission = self::calculate_commission($affiliate, $order_total);

        // Record the referral
        $wpdb->insert(
            self::get_referrals_table(),
            [
                'affiliate_id' => $affiliate_id,
                'order_id' => $order_id,
                'order_total' => $order_total,
                'commission_amount' => $commission,
                'visitor_ip' => self::get_visitor_ip(),
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%f', '%f', '%s', '%s']
        );

        // Add order note
        $order->add_order_note(sprintf(
            __('Affiliate referral: %s (Commission: %s)', 'peanut-license-server'),
            $affiliate->name,
            wc_price($commission)
        ));

        // Send notification to affiliate
        self::send_referral_notification($affiliate, $order, $commission);

        // Clear the cookie
        setcookie('peanut_affiliate', '', time() - 3600, '/');
    }

    /**
     * Calculate commission
     */
    private static function calculate_commission(object $affiliate, float $order_total): float {
        if ($affiliate->commission_type === self::COMMISSION_PERCENTAGE) {
            return round($order_total * ($affiliate->commission_rate / 100), 2);
        }

        return floatval($affiliate->commission_rate);
    }

    /**
     * Create a payout
     */
    public static function create_payout(int $affiliate_id, float $amount, array $referral_ids = []): ?int {
        global $wpdb;

        $result = $wpdb->insert(
            self::get_payouts_table(),
            [
                'affiliate_id' => $affiliate_id,
                'amount' => $amount,
                'referral_ids' => wp_json_encode($referral_ids),
                'status' => self::PAYOUT_PENDING,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%f', '%s', '%s', '%s']
        );

        if (!$result) {
            return null;
        }

        return $wpdb->insert_id;
    }

    /**
     * Process a payout
     */
    public static function process_payout(int $payout_id): array {
        global $wpdb;
        $payouts_table = self::get_payouts_table();
        $referrals_table = self::get_referrals_table();

        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$payouts_table} WHERE id = %d",
            $payout_id
        ));

        if (!$payout) {
            return ['success' => false, 'error' => __('Payout not found.', 'peanut-license-server')];
        }

        if ($payout->status !== self::PAYOUT_PENDING) {
            return ['success' => false, 'error' => __('Payout already processed.', 'peanut-license-server')];
        }

        $affiliate = self::get_affiliate($payout->affiliate_id);

        if (!$affiliate) {
            return ['success' => false, 'error' => __('Affiliate not found.', 'peanut-license-server')];
        }

        // Update status to processing
        $wpdb->update(
            $payouts_table,
            ['status' => self::PAYOUT_PROCESSING],
            ['id' => $payout_id],
            ['%s'],
            ['%d']
        );

        // Here you would integrate with PayPal, Stripe, etc.
        // For now, we'll just mark it as completed
        $payment_result = self::send_payment($affiliate, $payout->amount);

        if ($payment_result['success']) {
            // Mark payout as completed
            $wpdb->update(
                $payouts_table,
                [
                    'status' => self::PAYOUT_COMPLETED,
                    'processed_at' => current_time('mysql'),
                    'transaction_id' => $payment_result['transaction_id'] ?? null,
                ],
                ['id' => $payout_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            // Mark referrals as paid
            $referral_ids = json_decode($payout->referral_ids, true) ?: [];
            if (!empty($referral_ids)) {
                $ids_placeholder = implode(',', array_fill(0, count($referral_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$referrals_table} SET is_paid = 1, payout_id = %d WHERE id IN ({$ids_placeholder})",
                    $payout_id,
                    ...$referral_ids
                ));
            }

            // Send confirmation email
            self::send_payout_confirmation($affiliate, $payout->amount, $payment_result['transaction_id'] ?? '');

            return ['success' => true, 'transaction_id' => $payment_result['transaction_id'] ?? null];
        }

        // Mark payout as failed
        $wpdb->update(
            $payouts_table,
            [
                'status' => self::PAYOUT_FAILED,
                'notes' => $payment_result['error'] ?? 'Unknown error',
            ],
            ['id' => $payout_id],
            ['%s', '%s'],
            ['%d']
        );

        return ['success' => false, 'error' => $payment_result['error'] ?? __('Payment failed.', 'peanut-license-server')];
    }

    /**
     * Send payment (placeholder - integrate with payment provider)
     */
    private static function send_payment(object $affiliate, float $amount): array {
        // This is a placeholder. Integrate with PayPal, Stripe, etc.
        // For now, we'll simulate a successful payment

        return [
            'success' => true,
            'transaction_id' => 'TXN_' . strtoupper(wp_generate_password(12, false)),
        ];
    }

    /**
     * Get pending referrals for payout
     */
    public static function get_pending_referrals(int $affiliate_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::get_referrals_table() . "
             WHERE affiliate_id = %d AND order_id IS NOT NULL AND is_paid = 0
             ORDER BY created_at ASC",
            $affiliate_id
        ));
    }

    /**
     * Get affiliate payouts
     */
    public static function get_affiliate_payouts(int $affiliate_id): array {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::get_payouts_table() . " WHERE affiliate_id = %d ORDER BY created_at DESC",
            $affiliate_id
        ));
    }

    /**
     * AJAX handler for processing payout
     */
    public static function ajax_process_payout(): void {
        check_ajax_referer('peanut_affiliate_payout', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'peanut-license-server')]);
        }

        $payout_id = intval($_POST['payout_id'] ?? 0);

        if (!$payout_id) {
            wp_send_json_error(['message' => __('Invalid payout ID.', 'peanut-license-server')]);
        }

        $result = self::process_payout($payout_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Render affiliate dashboard shortcode
     */
    public static function render_affiliate_dashboard(): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to access your affiliate dashboard.', 'peanut-license-server') . '</p>';
        }

        $affiliate = self::get_affiliate_by_user(get_current_user_id());

        if (!$affiliate) {
            return '<p>' . esc_html__('You are not registered as an affiliate.', 'peanut-license-server') . '</p>';
        }

        ob_start();
        self::render_dashboard_template($affiliate);
        return ob_get_clean();
    }

    /**
     * Render dashboard template
     */
    private static function render_dashboard_template(object $affiliate): void {
        $stats = $affiliate->stats;
        $referrals = self::get_pending_referrals($affiliate->id);
        $payouts = self::get_affiliate_payouts($affiliate->id);
        ?>
        <div class="peanut-affiliate-dashboard">
            <h2><?php esc_html_e('Affiliate Dashboard', 'peanut-license-server'); ?></h2>

            <div class="affiliate-code-box">
                <label><?php esc_html_e('Your Affiliate Link', 'peanut-license-server'); ?></label>
                <input type="text" readonly value="<?php echo esc_url(home_url('?ref=' . $affiliate->code)); ?>" class="affiliate-link">
                <button type="button" class="copy-link-btn"><?php esc_html_e('Copy', 'peanut-license-server'); ?></button>
            </div>

            <div class="affiliate-stats">
                <div class="stat-card">
                    <span class="stat-value"><?php echo esc_html($stats->total_referrals); ?></span>
                    <span class="stat-label"><?php esc_html_e('Total Clicks', 'peanut-license-server'); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?php echo esc_html($stats->successful_referrals); ?></span>
                    <span class="stat-label"><?php esc_html_e('Conversions', 'peanut-license-server'); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?php echo esc_html($stats->conversion_rate); ?>%</span>
                    <span class="stat-label"><?php esc_html_e('Conversion Rate', 'peanut-license-server'); ?></span>
                </div>
                <div class="stat-card">
                    <span class="stat-value"><?php echo wc_price($stats->total_earnings); ?></span>
                    <span class="stat-label"><?php esc_html_e('Total Earnings', 'peanut-license-server'); ?></span>
                </div>
                <div class="stat-card highlight">
                    <span class="stat-value"><?php echo wc_price($stats->pending_earnings); ?></span>
                    <span class="stat-label"><?php esc_html_e('Pending Payout', 'peanut-license-server'); ?></span>
                </div>
            </div>

            <?php if (!empty($referrals)): ?>
                <h3><?php esc_html_e('Recent Referrals', 'peanut-license-server'); ?></h3>
                <table class="affiliate-referrals">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'peanut-license-server'); ?></th>
                            <th><?php esc_html_e('Order', 'peanut-license-server'); ?></th>
                            <th><?php esc_html_e('Amount', 'peanut-license-server'); ?></th>
                            <th><?php esc_html_e('Commission', 'peanut-license-server'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($referrals, 0, 10) as $referral): ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($referral->created_at))); ?></td>
                                <td>#<?php echo esc_html($referral->order_id); ?></td>
                                <td><?php echo wc_price($referral->order_total); ?></td>
                                <td><?php echo wc_price($referral->commission_amount); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <style>
            .peanut-affiliate-dashboard { max-width: 900px; margin: 0 auto; }
            .affiliate-code-box { display: flex; gap: 10px; margin-bottom: 30px; padding: 20px; background: #f5f5f5; border-radius: 8px; }
            .affiliate-code-box label { display: block; width: 100%; margin-bottom: 10px; font-weight: bold; }
            .affiliate-link { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
            .copy-link-btn { padding: 10px 20px; background: #0073aa; color: #fff; border: none; border-radius: 4px; cursor: pointer; }
            .affiliate-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 30px; }
            .stat-card { text-align: center; padding: 20px; background: #fff; border: 1px solid #ddd; border-radius: 8px; }
            .stat-card.highlight { background: #e8f5e9; border-color: #4caf50; }
            .stat-value { display: block; font-size: 24px; font-weight: bold; color: #333; }
            .stat-label { display: block; font-size: 12px; color: #666; margin-top: 5px; }
            .affiliate-referrals { width: 100%; border-collapse: collapse; }
            .affiliate-referrals th, .affiliate-referrals td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
            .affiliate-referrals th { background: #f5f5f5; }
        </style>
        <script>
        document.querySelector('.copy-link-btn')?.addEventListener('click', function() {
            var input = document.querySelector('.affiliate-link');
            input.select();
            document.execCommand('copy');
            this.textContent = '<?php echo esc_js(__('Copied!', 'peanut-license-server')); ?>';
            setTimeout(() => this.textContent = '<?php echo esc_js(__('Copy', 'peanut-license-server')); ?>', 2000);
        });
        </script>
        <?php
    }

    /**
     * Send welcome email
     */
    private static function send_welcome_email(int $affiliate_id): void {
        $affiliate = self::get_affiliate($affiliate_id);

        if (!$affiliate) {
            return;
        }

        $subject = sprintf(
            __('[%s] Welcome to Our Affiliate Program!', 'peanut-license-server'),
            get_bloginfo('name')
        );

        $message = sprintf(
            __("Welcome to our affiliate program, %s!\n\nYour affiliate code is: %s\n\nShare this link with your audience:\n%s\n\nYou'll earn %s commission on every sale.\n\nThank you for partnering with us!", 'peanut-license-server'),
            $affiliate->name,
            $affiliate->code,
            home_url('?ref=' . $affiliate->code),
            $affiliate->commission_type === self::COMMISSION_PERCENTAGE
                ? $affiliate->commission_rate . '%'
                : wc_price($affiliate->commission_rate)
        );

        wp_mail($affiliate->email, $subject, $message);
    }

    /**
     * Send referral notification
     */
    private static function send_referral_notification(object $affiliate, $order, float $commission): void {
        $subject = sprintf(
            __('[%s] New Referral Sale!', 'peanut-license-server'),
            get_bloginfo('name')
        );

        $message = sprintf(
            __("Great news! You just earned %s from a referral sale.\n\nOrder #%d\nOrder Total: %s\nYour Commission: %s\n\nKeep up the great work!", 'peanut-license-server'),
            wc_price($commission),
            $order->get_id(),
            wc_price($order->get_total()),
            wc_price($commission)
        );

        wp_mail($affiliate->email, $subject, $message);
    }

    /**
     * Send payout confirmation
     */
    private static function send_payout_confirmation(object $affiliate, float $amount, string $transaction_id): void {
        $subject = sprintf(
            __('[%s] Affiliate Payout Sent', 'peanut-license-server'),
            get_bloginfo('name')
        );

        $message = sprintf(
            __("Your affiliate payout of %s has been sent!\n\nTransaction ID: %s\nPayment Method: %s\n\nThank you for being a valued partner!", 'peanut-license-server'),
            wc_price($amount),
            $transaction_id,
            ucfirst($affiliate->payment_method)
        );

        wp_mail($affiliate->email, $subject, $message);
    }

    /**
     * Get visitor IP
     */
    private static function get_visitor_ip(): string {
        // Delegate to the shared trusted-proxy resolver so forwarded headers
        // (CF-Connecting-IP / X-Forwarded-For / X-Real-IP) are only honoured
        // behind a configured trusted proxy and cannot be spoofed to defeat
        // rate-limits / IP bans. See includes/client-ip-functions.php.
        return peanut_get_client_ip();
    }

    /**
     * Create database tables
     */
    public static function create_tables(): void {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Affiliates table
        $affiliates_sql = "CREATE TABLE IF NOT EXISTS " . self::get_affiliates_table() . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED DEFAULT NULL,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            code VARCHAR(20) NOT NULL UNIQUE,
            commission_type ENUM('percentage', 'fixed') DEFAULT 'percentage',
            commission_rate DECIMAL(10,2) DEFAULT 20.00,
            payment_email VARCHAR(255) DEFAULT NULL,
            payment_method VARCHAR(50) DEFAULT 'paypal',
            is_active TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_user_id (user_id),
            KEY idx_code (code),
            KEY idx_email (email)
        ) {$charset_collate};";

        // Referrals table
        $referrals_sql = "CREATE TABLE IF NOT EXISTS " . self::get_referrals_table() . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            affiliate_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED DEFAULT NULL,
            order_total DECIMAL(10,2) DEFAULT NULL,
            commission_amount DECIMAL(10,2) DEFAULT NULL,
            visitor_ip VARCHAR(45) DEFAULT NULL,
            referrer_url TEXT DEFAULT NULL,
            landing_page TEXT DEFAULT NULL,
            is_paid TINYINT(1) DEFAULT 0,
            payout_id BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY idx_affiliate_id (affiliate_id),
            KEY idx_order_id (order_id),
            KEY idx_is_paid (is_paid)
        ) {$charset_collate};";

        // Payouts table
        $payouts_sql = "CREATE TABLE IF NOT EXISTS " . self::get_payouts_table() . " (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            affiliate_id BIGINT UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            referral_ids TEXT DEFAULT NULL,
            status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
            transaction_id VARCHAR(255) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            KEY idx_affiliate_id (affiliate_id),
            KEY idx_status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($affiliates_sql);
        dbDelta($referrals_sql);
        dbDelta($payouts_sql);
    }
}

// Initialize
add_action('plugins_loaded', ['Peanut_Affiliate_System', 'init']);
