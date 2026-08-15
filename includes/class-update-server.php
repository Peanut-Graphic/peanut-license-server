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
        'formflow-lite' => [
            'name' => 'FormFlow Lite',
            'slug' => 'formflow-lite',
            'file' => 'formflow-lite/formflow-lite.php',
            'author' => 'Peanut Graphic',
            'homepage' => 'https://peanutgraphic.com/formflow-lite',
            'description' => 'Lightweight API-integrated enrollment forms for WordPress.',
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
     * Get download URL.
     *
     * Unified update delivery: the URL returned here is what the LEGACY
     * /updates/check + /updates/info responses hand to the peanut-suite /
     * formflow client updaters (via download_url/package). It now resolves to
     * the SAME canonical GitHub Releases package that the per-product update
     * mu-plugins (e.g. peanut-suite-update.php) and the /updates mu-route already
     * serve, so every client — legacy and mu-route — installs identical bytes
     * from one source of truth instead of a stale/missing self-hosted ZIP in
     * wp-content/uploads/<slug>/.
     *
     * Precedence:
     *   1. Explicit operator override — peanut_{slug}_download_url (escape hatch).
     *   2. GitHub Releases URL derived from the advertised version (canonical).
     *   3. Fallback — the signed self-hosted ?peanut_download URL, retained only
     *      for products with no advertised version so nothing regresses.
     *
     * @param string|null $license_key Optional license key for the download.
     * @return string Download URL.
     */
    public function get_download_url(?string $license_key = null): string {
        $slug = $this->product_slug;

        // 1) Explicit operator override in settings (external CDN, etc.).
        $custom_url = get_option("peanut_{$slug}_download_url", '');
        if (!empty($custom_url)) {
            return $custom_url;
        }

        // 2) Canonical GitHub Releases package (matches the mu-plugins).
        $github_url = $this->get_github_release_url();
        if ($github_url !== '') {
            return $github_url;
        }

        // 3) DEPRECATED fallback: signed self-hosted ?peanut_download URL, only
        //    reachable when NO version is advertised. Superseded by GitHub-release
        //    delivery above; in practice every product advertises a version, so
        //    this branch is effectively dead. Retained (not returned '') so a
        //    product with no advertised version does not regress to a broken URL.
        //    Safe to delete once every product advertises a version and all
        //    installs are off the ?peanut_download path.
        return $this->get_signed_download_url($license_key);
    }

    /**
     * Build the canonical GitHub Releases package URL for this product.
     *
     * Mirrors what the per-product update mu-plugins hardcode:
     *   https://github.com/{repo}/releases/download/v{version}/{slug}-{version}.zip
     * keyed on the advertised version option peanut_{slug}_version. The repo
     * defaults to the canonical peanutgraphic/{slug} but can be overridden
     * per-product via peanut_{slug}_github_repo.
     *
     * @return string Release URL, or '' when no version is advertised (caller
     *                then falls back to the self-hosted signed URL).
     */
    private function get_github_release_url(): string {
        $slug = $this->product_slug;

        $version = (string) get_option("peanut_{$slug}_version", '');
        if ($version === '') {
            return '';
        }

        $repo = (string) get_option("peanut_{$slug}_github_repo", 'peanutgraphic/' . $slug);

        // Build the URL literally. A normal semver (e.g. 4.0.7) is path-safe as
        // written, so do not encode it in a way that would mangle the dots.
        return "https://github.com/{$repo}/releases/download/v{$version}/{$slug}-{$version}.zip";
    }

    /**
     * Get a signed download URL with expiring token.
     *
     * DEPRECATED — superseded by GitHub-release delivery via get_download_url().
     * Only reached as the no-advertised-version fallback of get_download_url()
     * (and by the legacy WooCommerce/admin self-hosted download surfaces).
     * Retained only as a fallback; safe to delete once all installs are off the
     * ?peanut_download path.
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
     * DEPRECATED — superseded by GitHub-release delivery via get_download_url();
     * part of the self-hosted ?peanut_download / admin-ajax path, retained only
     * as a fallback and safe to delete once all installs are off it.
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
     * Get download file path.
     *
     * DEPRECATED — superseded by GitHub-release delivery via get_download_url().
     * Only used by the self-hosted ?peanut_download / serve_download path and the
     * admin "is a self-hosted ZIP staged?" status displays. Retained only as a
     * fallback; safe to delete once all installs are off the ?peanut_download path.
     *
     * Returns the first existing candidate in trust order. The deploy-managed,
     * non web-writable releases/ directory (pinned to the exact expected
     * filename for the trusted version) is preferred over the web-writable
     * uploads directory, so an attacker who can drop a file into uploads cannot
     * win a newest-mtime race and have their ZIP served.
     */
    public function get_download_file(): ?string {
        $upload_dir = wp_upload_dir();
        $allowed_roots = [
            PEANUT_LICENSE_SERVER_PATH . 'releases',
            ($upload_dir['basedir'] ?? '') . '/' . $this->product_slug,
        ];

        foreach ($this->get_download_file_candidates() as $candidate) {
            if (peanut_is_path_within_roots($candidate, $allowed_roots)) {
                return realpath($candidate) ?: null;
            }
        }

        return null;
    }

    /**
     * Build the ordered list of candidate download-file paths (most-trusted
     * first). Pure/no-IO except wp_upload_dir(); the newest-mtime uploads glob
     * is retained only as a last-resort fallback for releases already staged in
     * uploads, and is ordered AFTER the trusted releases and pinned paths.
     *
     * @return string[] Absolute candidate paths, most-trusted first.
     */
    public function get_download_file_candidates(): array {
        $slug = $this->product_slug;
        $version = (string) get_option("peanut_{$slug}_version", '1.0.0');

        // 1) Trusted, deploy-managed, NON web-writable releases directory,
        //    pinned to the exact expected filename for the trusted version.
        $releases_dir = PEANUT_LICENSE_SERVER_PATH . 'releases/';
        $candidates = [
            $releases_dir . "{$slug}-{$version}.zip",
            $releases_dir . "{$slug}.zip",
        ];

        // 2) Web-writable uploads directory — only reached if no trusted release
        //    exists. Pin the exact version before any newest-wins globbing.
        $upload_dir = wp_upload_dir();
        $base_path = ($upload_dir['basedir'] ?? '') . "/{$slug}/";
        $candidates[] = $base_path . "{$slug}-{$version}.zip";
        $candidates[] = $base_path . "{$slug}.zip";

        // 3) Last resort: newest-mtime match in uploads (legacy behaviour, kept
        //    so releases already staged in uploads keep working).
        if (is_dir($base_path)) {
            $glob = glob($base_path . "{$slug}-*.zip");
            if (!empty($glob)) {
                usort($glob, static function ($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                foreach ($glob as $file) {
                    $candidates[] = $file;
                }
            }
        }

        return $candidates;
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
     * Serve download file.
     *
     * DEPRECATED — superseded by GitHub-release delivery via get_download_url().
     * This streams the OLD self-hosted uploads/releases ZIP and is only reachable
     * through the deprecated /updates/download route and the ?peanut_download
     * handler. Retained only as a fallback; safe to delete once all installs are
     * off the ?peanut_download path. It must NEVER be reachable from the normal
     * update-check flow, which now hands out GitHub URLs directly.
     *
     * DEFAULT-DENY: never streams bytes unless the caller has already proven the
     * request is authorized (a valid signed download token or a resolving
     * valid+active license — see Peanut_API_Endpoints::download_plugin()). The
     * $authorized flag defaults to false so any new/forgotten caller fails
     * closed instead of leaking the full plugin ZIP.
     *
     * @param string|null $license_key Optional license key (for download logging).
     * @param bool        $authorized  Whether the caller verified authorization.
     */
    public function serve_download(?string $license_key = null, bool $authorized = false): void {
        if (!$authorized) {
            status_header(403);
            wp_die(
                __('Unauthorized. A valid download token or active license is required.', 'peanut-license-server'),
                403
            );
        }

        // Validate license if provided (for download logging only).
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
     * Validate license for updates.
     *
     * Feeds only the informational `can_download` field in the update-check
     * response (the actual egress is gated in download_plugin()/serve_download()).
     * DEFAULT-DENY: an empty or unknown license resolves to false rather than
     * advertising downloadability to anyone.
     */
    private function validate_license_for_update(?string $license_key): bool {
        if (!is_string($license_key) || $license_key === '') {
            return false;
        }

        $license = Peanut_License_Manager::get_by_key($license_key);

        if (!$license) {
            return false;
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
