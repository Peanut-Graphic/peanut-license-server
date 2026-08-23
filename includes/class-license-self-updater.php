<?php
/**
 * Peanut License Server Self-Updater
 *
 * Offers only the canonical signed GitHub release published by the License
 * Server's own update API. Package verification is registered separately by
 * the bootstrap through formflow-core's SignedUpdateGate.
 */

defined('ABSPATH') || exit;

class Peanut_License_Self_Updater {

    private const API_URL = 'https://www.peanutgraphic.com/wp-json/peanut-api/v1/updates/peanut-license-server';

    private const PLUGIN_SLUG = 'peanut-license-server';

    private const PLUGIN_FILE = 'peanut-license-server/peanut-license-server.php';

    private const TRUSTED_PACKAGE_HOSTS = ['peanutgraphic.com', 'github.com'];

    public function __construct() {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
    }

    public function check_for_update($transient) {
        if (!is_object($transient) || empty($transient->checked)) {
            return $transient;
        }

        $current = (string) ($transient->checked[self::PLUGIN_FILE] ?? PEANUT_LICENSE_SERVER_VERSION);
        $remote = $this->get_remote_update_info($current);

        if ($remote === null || version_compare($remote->version, $current, '<=')) {
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }

        $transient->response[self::PLUGIN_FILE] = (object) [
            'id' => self::PLUGIN_SLUG,
            'slug' => self::PLUGIN_SLUG,
            'plugin' => self::PLUGIN_FILE,
            'new_version' => $remote->version,
            'package' => $remote->download_url,
            'url' => $remote->homepage,
            'tested' => $remote->tested,
            'requires_php' => $remote->requires_php,
            'requires' => $remote->requires,
        ];

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || ($args->slug ?? '') !== self::PLUGIN_SLUG) {
            return $result;
        }

        $remote = $this->get_remote_update_info('0.0.0');
        if ($remote === null) {
            return $result;
        }

        return (object) [
            'name' => $remote->name,
            'slug' => self::PLUGIN_SLUG,
            'version' => $remote->version,
            'author' => $remote->author,
            'homepage' => $remote->homepage,
            'download_link' => $remote->download_url,
            'trunk' => $remote->download_url,
            'requires' => $remote->requires,
            'tested' => $remote->tested,
            'requires_php' => $remote->requires_php,
            'last_updated' => $remote->last_updated,
            'sections' => $remote->sections,
        ];
    }

    private function get_remote_update_info(string $current_version): ?object {
        $cache_key = 'peanut_license_server_self_update_' . md5($current_version);
        $cached = get_transient($cache_key);
        if (is_object($cached)) {
            return $cached;
        }

        $response = wp_remote_get(self::API_URL . '/' . rawurlencode($current_version), [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response));
        $info = $body->plugin_info ?? null;
        if (!is_object($info)) {
            return null;
        }

        $version = trim((string) ($info->version ?? $info->new_version ?? ''));
        $package = (string) ($info->download_url ?? $info->package ?? '');
        if (!preg_match('/^[0-9]+(\.[0-9]+){1,3}$/', $version)) {
            return null;
        }
        if (!\Peanut\FormCore\Update\PackageVerifier::isTrustedPackageUrl($package, self::TRUSTED_PACKAGE_HOSTS)) {
            return null;
        }

        $normalized = (object) [
            'name' => (string) ($info->name ?? 'Peanut License Server'),
            'version' => $version,
            'download_url' => $package,
            'author' => (string) ($info->author ?? 'Peanut Graphic'),
            'homepage' => (string) ($info->homepage ?? 'https://peanutgraphic.com/peanut-license-server'),
            'tested' => (string) ($info->tested ?? ''),
            'requires_php' => (string) ($info->requires_php ?? '8.0'),
            'requires' => (string) ($info->requires ?? '6.0'),
            'last_updated' => (string) ($info->last_updated ?? ''),
            'sections' => (array) ($info->sections ?? []),
        ];

        set_transient($cache_key, $normalized, 12 * HOUR_IN_SECONDS);

        return $normalized;
    }
}
