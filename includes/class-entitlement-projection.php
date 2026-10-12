<?php
/** Read-only, explicitly scoped HUB entitlement projection. */
defined('ABSPATH') || exit;

class Peanut_Entitlement_Projection {
    private const PREFIX = 'peanut_projection_';

    public static function register_routes(): void {
        register_rest_route('peanut-projection/v1', '/entitlements', [
            'methods' => 'GET', 'callback' => [self::class, 'read'],
            'permission_callback' => [self::class, 'permission'],
        ]);
    }

    private static function principal(WP_REST_Request $request): ?array {
        if ($request->get_method() !== 'GET') return null;
        $header = $request->get_header('Authorization');
        if (!is_string($header) || !preg_match('/^Bearer ([a-f0-9]{64})$/D', $header, $match)) return null;
        $hash = hash('sha256', $match[1]);
        $account = get_option(self::PREFIX . 'token_' . $hash, null);
        if (!is_string($account)) return null;
        $principal = get_option(self::PREFIX . 'account_' . hash('sha256', $account), null);
        if (!is_array($principal) || !isset($principal['token_hash']) || !is_string($principal['token_hash']) || !hash_equals($principal['token_hash'], $hash)) return null;
        return $principal;
    }

    public static function permission(WP_REST_Request $request) {
        return self::principal($request) !== null ? true : new WP_Error('projection_unauthorized', 'Invalid projection credential.', ['status' => 401]);
    }

    /** Operator-only provisioning; never creates or modifies an entitlement. */
    public static function provision(string $account, string $agency, array $license_ids, bool $rotate = false): string {
        global $wpdb;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $account) || !preg_match('/^[1-9][0-9]{0,18}$/D', $agency)) throw new InvalidArgumentException('Explicit source account and HUB agency ID required.');
        if (!$license_ids || count($license_ids) > 1000) throw new InvalidArgumentException('Provide 1–1000 explicit license IDs.');
        foreach ($license_ids as $id) if (!is_int($id) || $id <= 0) throw new InvalidArgumentException('License IDs must be positive integers.');
        $license_ids = array_values(array_unique($license_ids)); sort($license_ids);
        $key = self::PREFIX . 'account_' . hash('sha256', $account);
        $lock = $key . '_lock';
        if (!add_option($lock, 'provisioning', '', false)) throw new RuntimeException('Account provisioning already locked; inspect operator recovery before retrying.');
        try {
            $old = get_option($key, null);
            if ($old !== null && (!$rotate || $old['hub_agency_id'] !== $agency || $old['license_ids'] !== $license_ids)) throw new InvalidArgumentException('Existing binding requires explicit rotation with identical agency and license allowlist.');
            foreach ($license_ids as $id) {
                $found = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Peanut_License_Manager::get_table_name() . ' WHERE id = %d', $id));
                if ((int) $found !== $id) throw new InvalidArgumentException('Every assigned license must exist.');
            }
            $token = bin2hex(random_bytes(32)); $hash = hash('sha256', $token);
            $record = ['source_account_id' => $account, 'hub_agency_id' => $agency, 'license_ids' => $license_ids, 'token_hash' => $hash];
            if (!add_option(self::PREFIX . 'token_' . $hash, $account, '', false)) throw new RuntimeException('Unable to store token index.');
            update_option($key, $record, false);
            if (get_option($key) !== $record) { delete_option(self::PREFIX . 'token_' . $hash); throw new RuntimeException('Unable to persist binding.'); }
            if (isset($old['token_hash'])) delete_option(self::PREFIX . 'token_' . $old['token_hash']);
            return $token;
        } finally { delete_option($lock); }
    }

    public static function revoke(string $account): void {
        $key = self::PREFIX . 'account_' . hash('sha256', $account); $lock = $key . '_lock';
        if (!add_option($lock, 'revoking', '', false)) throw new RuntimeException('Account provisioning already locked.');
        try {
            $record = get_option($key, null);
            if (!is_array($record) || !isset($record['token_hash'])) return;
            delete_option(self::PREFIX . 'token_' . $record['token_hash']);
            $record['token_hash'] = null; update_option($key, $record, false);
        } finally { delete_option($lock); }
    }

    public static function read(WP_REST_Request $request) {
        global $wpdb;
        $principal = self::principal($request);
        if (!$principal) return self::permission($request);
        $rate_id = $principal['source_account_id'];
        if (Peanut_Rate_Limiter::is_rate_limited('entitlement_projection', $rate_id)) return new WP_Error('projection_rate_limit', 'Projection request limit reached.', ['status' => 429]);
        Peanut_Rate_Limiter::record_request('entitlement_projection', $rate_id);
        // The caller may page, but cannot select an agency, account or license.
        if (array_diff(array_keys($request->get_params()), ['cursor', 'per_page'])) return new WP_Error('projection_scope', 'Only pagination parameters are accepted.', ['status' => 400]);
        $cursor = $request->get_param('cursor') ?? '0'; $limit = $request->get_param('per_page') ?? '25';
        if (!preg_match('/^[0-9]{1,18}$/D', (string) $cursor) || !preg_match('/^[0-9]{1,3}$/D', (string) $limit) || (int) $limit < 1 || (int) $limit > 100) return new WP_Error('projection_page', 'Invalid pagination.', ['status' => 400]);
        $ids = array_filter($principal['license_ids'], fn($id) => $id > (int) $cursor);
        $items = []; $next = null;
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $licenses = Peanut_License_Manager::get_table_name(); $activations = Peanut_License_Manager::get_activations_table();
            // Never load license keys, customer fields, signing secrets or site URLs.
            $query = "SELECT l.id,l.product_id,l.tier,l.status,l.expires_at,l.max_activations,(SELECT COUNT(*) FROM {$activations} a WHERE a.license_id=l.id AND a.is_active=1) AS activation_count FROM {$licenses} l WHERE l.id IN ({$placeholders}) ORDER BY l.id ASC LIMIT %d";
            $rows = $wpdb->get_results($wpdb->prepare($query, ...array_merge(array_values($ids), [(int) $limit + 1])));
            if ($wpdb->last_error) return new WP_Error('projection_unavailable', 'Entitlement projection unavailable.', ['status' => 503]);
            $seen = [];
            foreach ($rows as $row) {
                $id = (int) $row->id;
                if (!in_array($id, $ids, true) || isset($seen[$id]) || !in_array($row->tier, ['free', 'pro', 'agency'], true) || !in_array($row->status, ['active', 'expired', 'suspended', 'revoked'], true) || (int) $row->product_id <= 0 || (int) $row->max_activations < 0 || (int) $row->activation_count < 0) return new WP_Error('projection_invalid', 'Entitlement projection unavailable.', ['status' => 503]);
                $seen[$id] = true;
                if (count($items) === (int) $limit) { $next = (string) end($items)['entitlement_id']; break; }
                $expires = null;
                if ($row->expires_at !== null && $row->expires_at !== '') {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row->expires_at, wp_timezone());
                    if (!$date || $date->format('Y-m-d H:i:s') !== $row->expires_at) return new WP_Error('projection_invalid', 'Entitlement projection unavailable.', ['status' => 503]);
                    $expires = $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                }
                $items[] = ['entitlement_id' => (string) $id, 'product_id' => (string) $row->product_id, 'tier' => $row->tier, 'status' => $row->status, 'expires_at' => $expires, 'activation_limit' => (int) $row->max_activations, 'activation_count' => (int) $row->activation_count];
            }
        }
        return new WP_REST_Response(['schema_version' => 1, 'source_account_id' => $principal['source_account_id'], 'hub_agency_id' => $principal['hub_agency_id'], 'observed_at' => gmdate('Y-m-d\TH:i:s\Z'), 'entitlements' => $items, 'next_cursor' => $next], 200, ['Cache-Control' => 'private, no-store']);
    }
}
