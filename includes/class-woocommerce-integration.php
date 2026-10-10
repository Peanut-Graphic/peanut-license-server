<?php
/**
 * WooCommerce Integration Class
 *
 * Adds license management to WooCommerce My Account.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_WooCommerce_Integration {

    /**
     * How long a claim link stays valid (24 hours).
     */
    public const CLAIM_TTL = 86400;

    /**
     * Minimum seconds between claim emails requested by one account.
     */
    public const CLAIM_REQUEST_INTERVAL = 300;

    /**
     * Constructor
     */
    public function __construct() {
        // Only load if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            return;
        }

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks(): void {
        // Add endpoint
        add_action('init', [$this, 'add_endpoint']);

        // Add menu item
        add_filter('woocommerce_account_menu_items', [$this, 'add_menu_item']);

        // Add content
        add_action('woocommerce_account_licenses_endpoint', [$this, 'render_licenses_page']);

        // Handle AJAX
        add_action('wp_ajax_peanut_deactivate_customer_site', [$this, 'ajax_deactivate_site']);

        // Enqueue scripts
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);

        // Flush rewrite rules on activation
        add_action('peanut_license_server_activate', [$this, 'flush_rewrite_rules']);
    }

    /**
     * Add WooCommerce endpoint
     */
    public function add_endpoint(): void {
        add_rewrite_endpoint('licenses', EP_ROOT | EP_PAGES);
    }

    /**
     * Flush rewrite rules
     */
    public function flush_rewrite_rules(): void {
        $this->add_endpoint();
        flush_rewrite_rules();
    }

    /**
     * Add menu item to My Account
     */
    public function add_menu_item(array $items): array {
        // Insert after dashboard
        $new_items = [];
        foreach ($items as $key => $label) {
            $new_items[$key] = $label;
            if ($key === 'dashboard') {
                $new_items['licenses'] = __('My Licenses', 'peanut-license-server');
            }
        }

        // If dashboard wasn't found, just append
        if (!isset($new_items['licenses'])) {
            $new_items['licenses'] = __('My Licenses', 'peanut-license-server');
        }

        return $new_items;
    }

    /**
     * Render licenses page.
     *
     * Only licenses whose user_id is the current user are listed. The account
     * email is NOT trusted: WooCommerce lets a customer register with, or
     * change to, any address without verifying it, so matching on email would
     * show another customer's keys and sites. Guest-checkout licenses (no
     * user_id) are linked through the verified claim flow instead.
     */
    public function render_licenses_page(): void {
        $user_id = get_current_user_id();

        if (!$user_id) {
            echo '<p>' . esc_html__('Please log in to view your licenses.', 'peanut-license-server') . '</p>';
            return;
        }

        $notice = $this->handle_portal_request($user_id);

        $licenses = Peanut_License_Manager::get_user_licenses($user_id);

        if ($notice !== null) {
            printf(
                '<div class="woocommerce-%1$s peanut-claim-notice" role="status">%2$s</div>',
                esc_attr($notice['success'] ? 'message' : 'error'),
                esc_html($notice['message'])
            );
        }

        $this->render_licenses_template($licenses);
        $this->render_claim_form($user_id);
    }

    /**
     * Handle a claim-link visit (GET) or a claim-email request (POST) on the
     * licenses endpoint. Returns a notice to show, or null.
     *
     * @return array{success: bool, message: string}|null
     */
    private function handle_portal_request(int $user_id): ?array {
        if (isset($_GET['peanut_claim_sig'])) {
            $args = [
                'peanut_claim_ids'     => sanitize_text_field(wp_unslash((string) ($_GET['peanut_claim_ids'] ?? ''))),
                'peanut_claim_expires' => sanitize_text_field(wp_unslash((string) ($_GET['peanut_claim_expires'] ?? ''))),
                'peanut_claim_sig'     => sanitize_text_field(wp_unslash((string) $_GET['peanut_claim_sig'])),
            ];
            $result = self::process_claim($args, $user_id);
            return ['success' => $result['success'], 'message' => $result['message']];
        }

        if (isset($_POST['peanut_claim_request'])) {
            $nonce = sanitize_text_field(wp_unslash((string) ($_POST['_peanut_claim_nonce'] ?? '')));
            if (!wp_verify_nonce($nonce, 'peanut_license_claim_request')) {
                return [
                    'success' => false,
                    'message' => __('Your session expired. Please try again.', 'peanut-license-server'),
                ];
            }

            $email = sanitize_email(wp_unslash((string) ($_POST['peanut_claim_email'] ?? '')));
            if ($email === '' || !is_email($email)) {
                return [
                    'success' => false,
                    'message' => __('Please enter a valid email address.', 'peanut-license-server'),
                ];
            }

            return ['success' => true, 'message' => self::request_claim_email($user_id, $email)];
        }

        return null;
    }

    /**
     * "Missing a license?" form: emails a claim link to a purchase address.
     */
    private function render_claim_form(int $user_id): void {
        $user = get_userdata($user_id);
        $default_email = $user ? (string) $user->user_email : '';
        ?>
        <form method="post" class="peanut-claim-form">
            <h4><?php esc_html_e('Missing a license?', 'peanut-license-server'); ?></h4>
            <p>
                <?php esc_html_e('Licenses bought without signing in are not linked to your account. Enter the email address used at checkout and we will send a confirmation link to that address. Open it while signed in to this account to link the licenses.', 'peanut-license-server'); ?>
            </p>
            <p>
                <label for="peanut-claim-email"><?php esc_html_e('Purchase email', 'peanut-license-server'); ?></label>
                <input type="email" id="peanut-claim-email" name="peanut_claim_email" required autocomplete="email" value="<?php echo esc_attr($default_email); ?>" />
            </p>
            <?php wp_nonce_field('peanut_license_claim_request', '_peanut_claim_nonce'); ?>
            <button type="submit" class="button" name="peanut_claim_request" value="1">
                <?php esc_html_e('Email me a confirmation link', 'peanut-license-server'); ?>
            </button>
        </form>
        <?php
    }

    /**
     * Whether $user_id owns $license. Ownership is the license's user_id and
     * nothing else; customer_email is never consulted (it is not verified).
     */
    public static function user_owns_license(object $license, int $user_id): bool {
        $owner_id = isset($license->user_id) ? (int) $license->user_id : 0;

        return $user_id > 0 && $owner_id > 0 && $owner_id === $user_id;
    }

    /**
     * Find licenses bought with $email that are not linked to any account.
     *
     * @return int[] License IDs.
     */
    private static function find_unclaimed_license_ids(string $email): array {
        global $wpdb;
        $table = Peanut_License_Manager::get_table_name();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, customer_email FROM {$table}
             WHERE customer_email = %s AND (user_id IS NULL OR user_id = 0)
             ORDER BY id ASC",
            $email
        ));

        return array_values(array_map(static fn($row) => (int) $row->id, (array) $rows));
    }

    /**
     * Send a claim link for the guest licenses bought with $email.
     *
     * The link goes to $email itself, so only someone who controls the
     * purchase inbox can complete the claim, and it only works for the account
     * that asked ($user_id). The reply is the same whether or not licenses
     * exist, so this cannot be used to learn who bought what. Rate limited per
     * account to stop it being used to flood an inbox.
     *
     * @return string Message to show the requester.
     */
    public static function request_claim_email(int $user_id, string $email): string {
        $email = sanitize_email($email);
        $reply = sprintf(
            /* translators: %s: email address */
            __('If any licenses bought with %s are waiting to be linked, we have emailed a confirmation link to that address. The link expires in 24 hours and only works while you are signed in to this account.', 'peanut-license-server'),
            $email
        );

        if ($user_id <= 0 || $email === '') {
            return $reply;
        }

        $throttle_key = 'peanut_lic_claim_sent_' . $user_id;
        if (get_transient($throttle_key) !== false) {
            return $reply;
        }
        set_transient($throttle_key, time(), self::CLAIM_REQUEST_INTERVAL);

        $license_ids = self::find_unclaimed_license_ids($email);
        if (empty($license_ids)) {
            return $reply;
        }

        $url = self::build_claim_url($license_ids, $user_id, $email, time() + self::CLAIM_TTL);
        if ($url === '') {
            return $reply;
        }

        $requester = get_userdata($user_id);
        $account = $requester ? (string) $requester->user_login : '#' . $user_id;

        $subject = sprintf(
            /* translators: %s: site name */
            __('[%s] Confirm linking your licenses to an account', 'peanut-license-server'),
            get_bloginfo('name')
        );

        $message = sprintf(
            /* translators: 1: number of licenses, 2: account username, 3: claim URL */
            __("Someone signed in as \"%2\$s\" asked to link %1\$d license(s) bought with this email address to their account.\n\nIf that was you, sign in to that account and open this link within 24 hours:\n\n%3\$s\n\nIf it was not you, ignore this email. Nothing changes unless the link is opened by that account.", 'peanut-license-server'),
            count($license_ids),
            $account,
            $url
        );

        wp_mail($email, $subject, $message);

        return $reply;
    }

    /**
     * Build a signed, expiring claim URL. The signature binds the exact
     * license IDs, the requesting user, the purchase email and the expiry.
     *
     * @param int[] $license_ids
     * @return string URL, or '' if no signing secret is available.
     */
    public static function build_claim_url(array $license_ids, int $user_id, string $email, int $expires): string {
        $ids = self::normalize_ids($license_ids);
        $signature = self::sign_claim($ids, $user_id, $email, $expires);

        if ($signature === '' || empty($ids)) {
            return '';
        }

        return add_query_arg(
            [
                'peanut_claim_ids'     => implode(',', $ids),
                'peanut_claim_expires' => $expires,
                'peanut_claim_sig'     => $signature,
            ],
            wc_get_account_endpoint_url('licenses')
        );
    }

    /**
     * Verify a claim link and bind its licenses to $user_id.
     *
     * Fails closed on: no signed-in user, malformed or expired link, a
     * signature that does not match this user, a license that is gone or
     * already linked, or licenses whose purchase email differs from the one
     * that was signed.
     *
     * @param array $args peanut_claim_ids / peanut_claim_expires / peanut_claim_sig.
     * @return array{success: bool, message: string, claimed: int}
     */
    public static function process_claim(array $args, int $user_id): array {
        $invalid = [
            'success' => false,
            'message' => __('This link is invalid or has expired. Request a new one below.', 'peanut-license-server'),
            'claimed' => 0,
        ];

        $ids       = self::normalize_ids(explode(',', (string) ($args['peanut_claim_ids'] ?? '')));
        $expires   = (string) ($args['peanut_claim_expires'] ?? '');
        $signature = (string) ($args['peanut_claim_sig'] ?? '');

        if ($user_id <= 0 || empty($ids) || $signature === '' || !ctype_digit($expires) || (int) $expires < time()) {
            return $invalid;
        }

        global $wpdb;
        $table = Peanut_License_Manager::get_table_name();
        $placeholders = implode(', ', array_fill(0, count($ids), '%d'));

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, customer_email FROM {$table}
             WHERE id IN ({$placeholders}) AND (user_id IS NULL OR user_id = 0)",
            ...$ids
        ));

        // Every signed license must still be unclaimed, and all must share
        // the purchase email the link was signed for.
        $found = self::normalize_ids(array_map(static fn($row) => (int) $row->id, $rows));
        if ($found !== $ids) {
            return $invalid;
        }

        $emails = array_unique(array_map(static fn($row) => strtolower(trim((string) $row->customer_email)), $rows));
        if (count($emails) !== 1) {
            return $invalid;
        }

        $expected = self::sign_claim($ids, $user_id, (string) reset($emails), (int) $expires);
        if ($expected === '' || !hash_equals($expected, $signature)) {
            return $invalid;
        }

        $claimed = 0;
        foreach ($ids as $license_id) {
            // The unclaimed condition is repeated in the UPDATE so a race with
            // another claim cannot rebind a license that was just linked.
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET user_id = %d
                 WHERE id = %d AND (user_id IS NULL OR user_id = 0)",
                $user_id,
                $license_id
            ));

            if ($updated) {
                $claimed++;
                if (class_exists('Peanut_Audit_Trail')) {
                    Peanut_Audit_Trail::log(Peanut_Audit_Trail::EVENT_LICENSE_TRANSFERRED, [
                        'license_id' => $license_id,
                        'new_value'  => ['user_id' => $user_id],
                        'context'    => ['via' => 'customer_claim_link'],
                    ]);
                }
            }
        }

        if ($claimed === 0) {
            return $invalid;
        }

        return [
            'success' => true,
            'message' => sprintf(
                /* translators: %d: number of licenses */
                _n('%d license is now linked to your account.', '%d licenses are now linked to your account.', $claimed, 'peanut-license-server'),
                $claimed
            ),
            'claimed' => $claimed,
        ];
    }

    /**
     * HMAC over the claim. Keyed with the site's auth salt; '' when no salt
     * is configured so callers fail closed.
     *
     * @param int[] $ids Normalized license IDs.
     */
    private static function sign_claim(array $ids, int $user_id, string $email, int $expires): string {
        $secret = wp_salt('auth');
        if ($secret === '' || $user_id <= 0) {
            return '';
        }

        $payload = implode('|', [
            'peanut_license_claim_v1',
            implode(',', $ids),
            $user_id,
            strtolower(trim($email)),
            $expires,
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * @param array $ids
     * @return int[] Unique positive IDs, ascending.
     */
    private static function normalize_ids(array $ids): array {
        $ids = array_filter(array_map('absint', $ids));
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Render licenses template
     */
    private function render_licenses_template(array $licenses): void {
        ?>
        <div class="peanut-licenses-portal">
            <?php if (empty($licenses)): ?>
                <div class="peanut-no-licenses">
                    <p><?php esc_html_e("You don't have any licenses yet.", 'peanut-license-server'); ?></p>
                    <a href="<?php echo esc_url(home_url('/peanut-suite/pricing/')); ?>" class="button">
                        <?php esc_html_e('Get Peanut Suite', 'peanut-license-server'); ?>
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($licenses as $license): ?>
                    <div class="peanut-license-card" data-license-id="<?php echo esc_attr($license->id); ?>">
                        <div class="license-header">
                            <div class="license-key">
                                <label><?php esc_html_e('License Key', 'peanut-license-server'); ?></label>
                                <code class="copyable" data-copy="<?php echo esc_attr($license->license_key); ?>">
                                    <?php echo esc_html($license->license_key); ?>
                                </code>
                                <button type="button" class="copy-btn" title="<?php esc_attr_e('Copy to clipboard', 'peanut-license-server'); ?>">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                </button>
                            </div>
                            <span class="license-tier tier-<?php echo esc_attr($license->tier); ?>">
                                <?php echo esc_html(Peanut_License_Manager::TIERS[$license->tier]['name'] ?? ucfirst($license->tier)); ?>
                            </span>
                        </div>

                        <div class="license-meta">
                            <div class="meta-item">
                                <span class="meta-label"><?php esc_html_e('Status', 'peanut-license-server'); ?></span>
                                <span class="license-status status-<?php echo esc_attr($license->status); ?>">
                                    <?php echo esc_html(ucfirst($license->status)); ?>
                                </span>
                            </div>

                            <div class="meta-item">
                                <span class="meta-label"><?php esc_html_e('Activations', 'peanut-license-server'); ?></span>
                                <span class="activations-count">
                                    <?php echo esc_html($license->activations_count); ?> / <?php echo esc_html($license->max_activations); ?>
                                </span>
                            </div>

                            <div class="meta-item">
                                <span class="meta-label"><?php esc_html_e('Expires', 'peanut-license-server'); ?></span>
                                <span class="expires-date <?php echo $license->expires_at && strtotime($license->expires_at) < time() ? 'expired' : ''; ?>">
                                    <?php
                                    if ($license->expires_at) {
                                        echo esc_html(date_i18n(get_option('date_format'), strtotime($license->expires_at)));
                                    } else {
                                        esc_html_e('Never', 'peanut-license-server');
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>

                        <?php $active_sites = array_filter($license->activations, fn($a) => $a->is_active); ?>
                        <?php if (!empty($active_sites)): ?>
                            <div class="license-sites">
                                <h4><?php esc_html_e('Activated Sites', 'peanut-license-server'); ?></h4>
                                <ul class="sites-list">
                                    <?php foreach ($active_sites as $site): ?>
                                        <li class="site-item" data-activation-id="<?php echo esc_attr($site->id); ?>">
                                            <div class="site-info">
                                                <span class="site-url"><?php echo esc_html($site->site_url); ?></span>
                                                <?php if ($site->site_name): ?>
                                                    <span class="site-name"><?php echo esc_html($site->site_name); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="deactivate-site-btn" data-activation-id="<?php echo esc_attr($site->id); ?>">
                                                <?php esc_html_e('Deactivate', 'peanut-license-server'); ?>
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="license-actions">
                            <?php $download_url = self::get_portal_download_url($license); ?>
                            <?php if ($download_url !== ''): ?>
                                <a href="<?php echo esc_url($download_url); ?>" class="button download-btn" rel="nofollow">
                                    <?php esc_html_e('Download Plugin', 'peanut-license-server'); ?>
                                </a>
                            <?php endif; ?>

                            <?php if ($license->status === 'expired'): ?>
                                <a href="<?php echo esc_url(wc_get_account_endpoint_url('subscriptions')); ?>" class="button renew-btn">
                                    <?php esc_html_e('Renew License', 'peanut-license-server'); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="peanut-help-text">
                    <h4><?php esc_html_e('How to activate your license', 'peanut-license-server'); ?></h4>
                    <ol>
                        <li><?php esc_html_e('Download and install the Peanut Suite plugin on your WordPress site', 'peanut-license-server'); ?></li>
                        <li><?php esc_html_e('Go to Peanut Suite → Settings in your WordPress admin', 'peanut-license-server'); ?></li>
                        <li><?php esc_html_e('Enter your license key and click Activate', 'peanut-license-server'); ?></li>
                    </ol>
                </div>
            <?php endif; ?>
        </div>
        <?php
        // CSS is loaded via wp_enqueue_style('peanut-woocommerce-portal') in enqueue_scripts()
    }

    /**
     * Download link for the portal: a short-lived signed token, so the
     * license key never appears in a URL (browser history, proxy and server
     * logs, Referer headers). The download route already accepts these
     * tokens. Only offered for a license that may download right now, which
     * is the same rule the route applies to a license key; '' otherwise.
     */
    public static function get_portal_download_url(object $license, string $plugin = 'peanut-suite'): string {
        if (!Peanut_License_Manager::is_valid($license)) {
            return '';
        }

        $token = peanut_generate_download_token($plugin, '', time() + HOUR_IN_SECONDS);
        if ($token === '') {
            return '';
        }

        return add_query_arg(
            [
                'plugin' => $plugin,
                'token'  => $token,
            ],
            rest_url('peanut-api/v1/updates/download')
        );
    }

    /**
     * Enqueue scripts
     */
    public function enqueue_scripts(): void {
        if (!is_account_page()) {
            return;
        }

        // Enqueue portal styles
        wp_enqueue_style(
            'peanut-woocommerce-portal',
            PEANUT_LICENSE_SERVER_URL . 'assets/css/woocommerce-portal.css',
            [],
            PEANUT_LICENSE_SERVER_VERSION
        );

        wp_add_inline_script('jquery', '
            jQuery(document).ready(function($) {
                // Copy license key
                $(".copy-btn").on("click", function() {
                    var code = $(this).siblings("code").data("copy");
                    navigator.clipboard.writeText(code).then(function() {
                        alert("' . esc_js(__('License key copied to clipboard!', 'peanut-license-server')) . '");
                    });
                });

                // Deactivate site
                $(".deactivate-site-btn").on("click", function() {
                    if (!confirm("' . esc_js(__('Are you sure you want to deactivate this site?', 'peanut-license-server')) . '")) {
                        return;
                    }

                    var $btn = $(this);
                    var $item = $btn.closest(".site-item");
                    var activationId = $btn.data("activation-id");

                    $btn.prop("disabled", true).text("' . esc_js(__('Deactivating...', 'peanut-license-server')) . '");

                    $.ajax({
                        url: "' . esc_url(admin_url('admin-ajax.php')) . '",
                        type: "POST",
                        data: {
                            action: "peanut_deactivate_customer_site",
                            nonce: "' . wp_create_nonce('peanut_customer_portal') . '",
                            activation_id: activationId
                        },
                        success: function(response) {
                            if (response.success) {
                                $item.slideUp(200, function() {
                                    $(this).remove();
                                    // Update count
                                    var $card = $btn.closest(".peanut-license-card");
                                    var $count = $card.find(".activations-count");
                                    var text = $count.text();
                                    var match = text.match(/(\d+) \/ (\d+)/);
                                    if (match) {
                                        var used = parseInt(match[1]) - 1;
                                        $count.text(used + " / " + match[2]);
                                    }
                                });
                            } else {
                                alert(response.data.message || "' . esc_js(__('Failed to deactivate site.', 'peanut-license-server')) . '");
                                $btn.prop("disabled", false).text("' . esc_js(__('Deactivate', 'peanut-license-server')) . '");
                            }
                        },
                        error: function() {
                            alert("' . esc_js(__('An error occurred.', 'peanut-license-server')) . '");
                            $btn.prop("disabled", false).text("' . esc_js(__('Deactivate', 'peanut-license-server')) . '");
                        }
                    });
                });
            });
        ');
    }

    /**
     * AJAX handler for deactivating site
     */
    public function ajax_deactivate_site(): void {
        check_ajax_referer('peanut_customer_portal', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Please log in.', 'peanut-license-server')]);
        }

        $activation_id = intval($_POST['activation_id'] ?? 0);

        if (!$activation_id) {
            wp_send_json_error(['message' => __('Invalid activation.', 'peanut-license-server')]);
        }

        // Verify the user owns the license this activation belongs to. Only
        // the license's user_id counts: the account email is not verified by
        // WooCommerce, so it must never grant access to someone's sites.
        global $wpdb;
        $activation = $wpdb->get_row($wpdb->prepare(
            "SELECT a.id, a.license_id, l.user_id
             FROM {$wpdb->prefix}peanut_activations a
             JOIN {$wpdb->prefix}peanut_licenses l ON a.license_id = l.id
             WHERE a.id = %d",
            $activation_id
        ));

        if (!$activation) {
            wp_send_json_error(['message' => __('Activation not found.', 'peanut-license-server')]);
        }

        $user_owns = self::user_owns_license($activation, get_current_user_id())
            || current_user_can('manage_options');

        if (!$user_owns) {
            wp_send_json_error(['message' => __('You do not have permission to deactivate this site.', 'peanut-license-server')]);
        }

        $result = Peanut_License_Manager::deactivate_site($activation_id);

        if ($result) {
            wp_send_json_success(['message' => __('Site deactivated.', 'peanut-license-server')]);
        } else {
            wp_send_json_error(['message' => __('Failed to deactivate site.', 'peanut-license-server')]);
        }
    }
}

// Initialize
new Peanut_WooCommerce_Integration();
