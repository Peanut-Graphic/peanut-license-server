<?php
/**
 * Update Server Class
 *
 * Handles plugin update checks and downloads for self-hosted updates.
 * Supports multiple products (Peanut Suite, FormFlow, etc.)
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_Update_Server {

    /**
     * Supported products configuration
     */
    public const PRODUCTS = [
        'peanut-suite' => [
            'name' => 'Peanut Suite',
            'slug' => 'peanut-suite',
            'file' => 'peanut-suite/peanut-suite.php',
            'author' => 'Peanut Graphic',
            'homepage' => 'https://peanutgraphic.com/peanut-suite',
            'description' => 'Complete marketing toolkit - UTM campaigns, link management, lead tracking, and analytics.',
        ],
        'peanut-connect' => [
            'name' => 'Peanut Connect',
            'slug' => 'peanut-connect',
            'file' => 'peanut-connect/peanut-connect.php',
            'author' => 'Peanut Graphic',
            'homepage' => 'https://peanutgraphic.com/peanut-connect',
            'description' => 'Remote site monitoring and management connector.',
        ],
        'formflow' => [
            'name' => 'FormFlow',
            'slug' => 'formflow',
            'file' => 'formflow/formflow.php',
            'author' => 'Peanut Graphic',
            'homepage' => 'https://peanutgraphic.com/formflow',
            'description' => 'Beautiful, powerful forms for WordPress.',
        ],
        'peanut-booker' => [
            'name' => 'Peanut Booker',
            'slug' => 'peanut-booker',
            'file' => 'peanut-booker/peanut-booker.php',
            'author' => 'Peanut Graphic',
            'homepage' => 'https://peanutgraphic.com/peanut-booker',
            'description' => 'A membership and booking platform connecting performers with event organizers.',
        ],
    ];

    /**
     * Current product slug
     */
    private string $product_slug = 'peanut-suite';

    /**
     * Constructor
     */
    public function __construct(string $product_slug = 'peanut-suite') {
        if (isset(self::PRODUCTS[$product_slug])) {
            $this->product_slug = $product_slug;
        }
    }

    /**
     * Get product config
     */
    public function get_product_config(): array {
        return self::PRODUCTS[$this->product_slug] ?? self::PRODUCTS['peanut-suite'];
    }

    /**
     * Check if product is valid
     */
    public static function is_valid_product(string $slug): bool {
        return isset(self::PRODUCTS[$slug]);
    }

    /**
     * Get all products
     */
    public static function get_all_products(): array {
        return self::PRODUCTS;
    }

    /**
     * Get plugin info for update check
     */
    public function get_plugin_info(?string $license_key = null): array {
        $product = $this->get_product_config();
        $slug = $this->product_slug;

        $version = get_option("peanut_{$slug}_version", '1.0.0');
        $requires_wp = get_option("peanut_{$slug}_requires_wp", '6.0');
        $requires_php = get_option("peanut_{$slug}_requires_php", '8.0');
        $tested_wp = get_option("peanut_{$slug}_tested_wp", '6.4');
        $changelog = get_option("peanut_{$slug}_changelog", '');

        // Get download URL
        $download_url = $this->get_download_url($license_key);

        return [
            'name' => $product['name'],
            'slug' => $slug,
            'version' => $version,
            'new_version' => $version,
            'author' => '<a href="https://peanutgraphic.com">' . $product['author'] . '</a>',
            'author_profile' => 'https://peanutgraphic.com',
            'homepage' => $product['homepage'],
            'download_url' => $download_url,
            'package' => $download_url,
            'requires' => $requires_wp,
            'tested' => $tested_wp,
            'requires_php' => $requires_php,
            'last_updated' => get_option("peanut_{$slug}_last_updated", date('Y-m-d')),
            'sections' => [
                'description' => $this->get_description(),
                'installation' => $this->get_installation_instructions(),
                'changelog' => $changelog ?: $this->get_default_changelog($version),
                'faq' => $this->get_faq(),
            ],
            'banners' => [
                'low' => home_url("/wp-content/uploads/{$slug}/banner-772x250.png"),
                'high' => home_url("/wp-content/uploads/{$slug}/banner-1544x500.png"),
            ],
            'icons' => [
                '1x' => home_url("/wp-content/uploads/{$slug}/icon-128x128.png"),
                '2x' => home_url("/wp-content/uploads/{$slug}/icon-256x256.png"),
            ],
        ];
    }

    /**
     * Check for updates
     */
    public function check_update(string $current_version, ?string $license_key = null): array {
        $slug = $this->product_slug;
        $latest_version = get_option("peanut_{$slug}_version", '1.0.0');

        $has_update = version_compare($latest_version, $current_version, '>');

        // Log the check
        $this->log_update_check($license_key, $current_version, $latest_version);

        if (!$has_update) {
            return [
                'update_available' => false,
                'current_version' => $current_version,
                'latest_version' => $latest_version,
            ];
        }

        // Check if license is valid for updates (pro/agency only for certain features)
        $license_valid = $this->validate_license_for_update($license_key);

        return [
            'update_available' => true,
            'current_version' => $current_version,
            'latest_version' => $latest_version,
            'can_download' => $license_valid,
            'plugin_info' => $this->get_plugin_info($license_key),
        ];
    }

    /**
     * Get download URL
     * Returns a signed URL for secure downloads
     *
     * @param string|null $license_key Optional license key for the download.
     * @return string Signed download URL.
     */
    public function get_download_url(?string $license_key = null): string {
        $slug = $this->product_slug;

        // Check for custom download URL in settings (external CDN, etc.)
        $custom_url = get_option("peanut_{$slug}_download_url", '');
        if (!empty($custom_url)) {
            return $custom_url;
        }

        // Generate signed download URL for security
        return $this->get_signed_download_url($license_key);
    }

    /**
     * Get a signed download URL with expiring token.
     *
     * @param string|null $license_key Optional license key.
     * @param int $expires_in Seconds until token expires (default 1 hour).
     * @return string Signed download URL.
     */
    public function get_signed_download_url(?string $license_key = null, int $expires_in = 3600): string {
        $slug = $this->product_slug;

        // Generate secure token
        $token = peanut_generate_download_token($slug, $license_key ?? '', time() + $expires_in);

        // Build URL with signed parameters
        $params = [
            'peanut_download' => '1',
            'plugin' => $slug,
            'token' => $token,
        ];

        if ($license_key) {
            $params['license'] = $license_key;
        }

        return add_query_arg($params, home_url('/'));
    }

    /**
     * Get AJAX download URL (alternative to signed direct URL).
     *
     * @param string|null $license_key Optional license key.
     * @return string AJAX download URL.
     */
    public function get_ajax_download_url(?string $license_key = null): string {
        $params = [
            'action' => 'peanut_download_plugin',
            'plugin' => $this->product_slug,
        ];

        if ($license_key) {
            $params['license'] = $license_key;
        }

        return add_query_arg($params, admin_url('admin-ajax.php'));
    }

    /**
     * Get download file path
     */
    public function get_download_file(): ?string {
        $slug = $this->product_slug;
        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'] . "/{$slug}/";

        // Check for exact filename first
        $exact_file = $base_path . "{$slug}.zip";
        if (file_exists($exact_file)) {
            return $exact_file;
        }

        // Check for versioned filename (slug-x.x.x.zip)
        if (is_dir($base_path)) {
            $files = glob($base_path . "{$slug}-*.zip");
            if (!empty($files)) {
                // Sort by modification time, newest first
                usort($files, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                return $files[0];
            }
        }

        // Check alternative location
        $alt_file = PEANUT_LICENSE_SERVER_PATH . "releases/{$slug}.zip";
        if (file_exists($alt_file)) {
            return $alt_file;
        }

        return null;
    }

    /**
     * Get download file info
     */
    public function get_download_file_info(): ?array {
        $file = $this->get_download_file();

        if (!$file) {
            return null;
        }

        return [
            'path' => $file,
            'filename' => basename($file),
            'size' => filesize($file),
            'modified' => filemtime($file),
        ];
    }

    /**
     * Serve download file
     */
    public function serve_download(?string $license_key = null): void {
        // Validate license if provided
        if ($license_key) {
            $license = Peanut_License_Manager::get_by_key($license_key);

            if ($license && Peanut_License_Manager::is_valid($license)) {
                $this->log_download($license->id, $license_key);
            }
        }

        $file = $this->get_download_file();

        if (!$file) {
            wp_die(__('Download file not found.', 'peanut-license-server'), 404);
        }

        // Set headers
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="peanut-suite.zip"');
        header('Content-Length: ' . filesize($file));
        header('Pragma: no-cache');
        header('Expires: 0');

        // Output file
        readfile($file);
        exit;
    }

    /**
     * Validate license for updates
     */
    private function validate_license_for_update(?string $license_key): bool {
        // Free tier can still download (they just get free features)
        if (empty($license_key)) {
            return true;
        }

        $license = Peanut_License_Manager::get_by_key($license_key);

        if (!$license) {
            return true; // Allow download, validation happens in plugin
        }

        return Peanut_License_Manager::is_valid($license);
    }

    /**
     * Log update check
     */
    private function log_update_check(?string $license_key, string $current_version, string $new_version): void {
        global $wpdb;

        $license_id = null;
        if ($license_key) {
            $license = Peanut_License_Manager::get_by_key($license_key);
            $license_id = $license ? $license->id : null;
        }

        $wpdb->insert(
            $wpdb->prefix . 'peanut_update_logs',
            [
                'license_id' => $license_id,
                'site_url' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : null,
                'plugin_version' => $current_version,
                'new_version' => $new_version,
                'action' => 'check',
                'ip_address' => $this->get_client_ip(),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Log download
     */
    private function log_download(?int $license_id, ?string $license_key): void {
        global $wpdb;

        $version = get_option('peanut_license_server_plugin_version', '1.0.0');

        $wpdb->insert(
            $wpdb->prefix . 'peanut_update_logs',
            [
                'license_id' => $license_id,
                'site_url' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : null,
                'plugin_version' => null,
                'new_version' => $version,
                'action' => 'download',
                'ip_address' => $this->get_client_ip(),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
    }

    /**
     * Get client IP
     */
    private function get_client_ip(): string {
        // Delegate to the shared trusted-proxy resolver so forwarded headers
        // (CF-Connecting-IP / X-Forwarded-For / X-Real-IP) are only honoured
        // behind a configured trusted proxy and cannot be spoofed to defeat
        // rate-limits / IP bans. See includes/client-ip-functions.php.
        return peanut_get_client_ip();
    }

    /**
     * Get plugin description
     */
    private function get_description(): string {
        return <<<HTML
<h3>The Complete Marketing Toolkit for WordPress</h3>

<p>Peanut Suite is an all-in-one marketing toolkit that helps you track campaigns, manage links, organize contacts, and grow your business.</p>

<h4>Features</h4>
<ul>
    <li><strong>UTM Builder</strong> - Create and manage UTM-tagged URLs for campaign tracking</li>
    <li><strong>Link Shortener</strong> - Generate branded short links with click analytics</li>
    <li><strong>Contact Manager</strong> - Organize and segment your leads and customers</li>
    <li><strong>Popup Builder</strong> (Pro) - Create engaging popups to capture leads</li>
    <li><strong>Site Monitor</strong> (Agency) - Monitor uptime and performance across client sites</li>
    <li><strong>Analytics Dashboard</strong> - Track clicks, conversions, and campaign performance</li>
</ul>

<h4>Pricing Tiers</h4>
<ul>
    <li><strong>Free</strong> - UTM Builder, Link Shortener, Contact Manager, Dashboard (1 site)</li>
    <li><strong>Pro</strong> - All Free features + Popups, Analytics, Export (3 sites)</li>
    <li><strong>Agency</strong> - All Pro features + Site Monitor, White Label, Priority Support (25 sites)</li>
</ul>
HTML;
    }

    /**
     * Get installation instructions
     */
    private function get_installation_instructions(): string {
        return <<<HTML
<ol>
    <li>Upload the <code>peanut-suite</code> folder to the <code>/wp-content/plugins/</code> directory</li>
    <li>Activate the plugin through the 'Plugins' menu in WordPress</li>
    <li>Go to <strong>Peanut Suite</strong> in your admin menu</li>
    <li>Enter your license key in Settings to unlock premium features</li>
</ol>

<h4>Requirements</h4>
<ul>
    <li>WordPress 6.0 or higher</li>
    <li>PHP 8.0 or higher</li>
</ul>
HTML;
    }

    /**
     * Get default changelog
     */
    private function get_default_changelog(string $version): string {
        return <<<HTML
<h4>{$version}</h4>
<ul>
    <li>Initial release</li>
    <li>UTM Builder with campaign management</li>
    <li>Link shortener with QR codes</li>
    <li>Contact manager with segmentation</li>
    <li>Popup builder (Pro)</li>
    <li>Site monitor (Agency)</li>
    <li>Analytics dashboard</li>
</ul>
HTML;
    }

    /**
     * Get FAQ
     */
    private function get_faq(): string {
        return <<<HTML
<h4>How do I activate my license?</h4>
<p>Go to Peanut Suite > Settings in your WordPress admin, enter your license key, and click Activate.</p>

<h4>Can I use one license on multiple sites?</h4>
<p>Yes! Pro licenses work on 3 sites, and Agency licenses work on 25 sites. You can manage your activations from your account at peanutgraphic.com.</p>

<h4>What happens when my license expires?</h4>
<p>The plugin will continue to work, but premium features will be disabled and you won't receive updates. Renew your license to restore full functionality.</p>

<h4>How do I get support?</h4>
<p>Pro and Agency users can submit support tickets at peanutgraphic.com. Free users can use our community forums.</p>
HTML;
    }

    /**
     * Update plugin version (admin function)
     */
    public static function update_version(string $version, string $changelog = ''): bool {
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return false;
        }

        update_option('peanut_license_server_plugin_version', $version);
        update_option('peanut_license_server_last_updated', date('Y-m-d'));

        if (!empty($changelog)) {
            $existing_changelog = get_option('peanut_license_server_changelog', '');
            $new_changelog = "<h4>{$version}</h4>\n{$changelog}\n\n{$existing_changelog}";
            update_option('peanut_license_server_changelog', $new_changelog);
        }

        return true;
    }

    /**
     * Get update statistics
     */
    public static function get_statistics(array $args = []): array {
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_update_logs';

        $defaults = [
            'days' => 30,
        ];
        $args = wp_parse_args($args, $defaults);

        $since = date('Y-m-d H:i:s', strtotime("-{$args['days']} days"));

        $stats = [
            'total_checks' => 0,
            'total_downloads' => 0,
            'unique_sites' => 0,
            'by_version' => [],
            'by_day' => [],
        ];

        // Total counts
        $counts = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM(CASE WHEN action = 'check' THEN 1 ELSE 0 END) as checks,
                    SUM(CASE WHEN action = 'download' THEN 1 ELSE 0 END) as downloads,
                    COUNT(DISTINCT site_url) as sites
                FROM {$table}
                WHERE created_at >= %s",
                $since
            )
        );

        $stats['total_checks'] = (int) ($counts->checks ?? 0);
        $stats['total_downloads'] = (int) ($counts->downloads ?? 0);
        $stats['unique_sites'] = (int) ($counts->sites ?? 0);

        // By version
        $versions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT plugin_version, COUNT(*) as count
                FROM {$table}
                WHERE created_at >= %s AND plugin_version IS NOT NULL
                GROUP BY plugin_version
                ORDER BY count DESC",
                $since
            )
        );

        foreach ($versions as $row) {
            $stats['by_version'][$row->plugin_version] = (int) $row->count;
        }

        // By day
        $daily = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) as date, action, COUNT(*) as count
                FROM {$table}
                WHERE created_at >= %s
                GROUP BY DATE(created_at), action
                ORDER BY date ASC",
                $since
            )
        );

        foreach ($daily as $row) {
            if (!isset($stats['by_day'][$row->date])) {
                $stats['by_day'][$row->date] = ['checks' => 0, 'downloads' => 0];
            }
            $stats['by_day'][$row->date][$row->action . 's'] = (int) $row->count;
        }

        return $stats;
    }
}
