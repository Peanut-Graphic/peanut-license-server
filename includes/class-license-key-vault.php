<?php
/**
 * License key vault: encryption at rest and keyed lookup hashes.
 *
 * License keys are bearer credentials (16 hex chars, 64 bits). They used to
 * sit in the licenses table as plaintext next to an unkeyed SHA-256, so a
 * database leak exposed every key directly, and even without the plaintext a
 * 64-bit keyspace behind a bare SHA-256 is brute-forceable offline.
 *
 * With PEANUT_LICENSE_KEY_SECRET defined (wp-config.php or environment; base64
 * of at least 32 random bytes), two subkeys are derived with HKDF-SHA256:
 *   - an XChaCha20-Poly1305 key; stored keys become "plk1:" + base64(nonce||ct)
 *   - an HMAC-SHA256 key; license_key_hash becomes HMAC(normalized key)
 * Customers and admins still see their key: it is decrypted on read.
 *
 * Without the secret nothing changes (plaintext + SHA-256, the pre-1.8 state)
 * and Site Health reports it. Legacy SHA-256 hashes stay accepted for lookup
 * until the sweep has converted every row.
 *
 * LOSING THE SECRET MAKES EVERY ENCRYPTED KEY UNRECOVERABLE AND UNFINDABLE.
 * Keep a copy outside the server (password manager) before enabling it.
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_License_Key_Vault {

    public const PREFIX = 'plk1:';
    public const SECRET_CONSTANT = 'PEANUT_LICENSE_KEY_SECRET';
    public const CANARY_OPTION = 'peanut_license_key_vault_canary';
    /** Set by schema 1.8.0 once license_key can hold ciphertext. */
    public const COLUMN_READY_OPTION = 'peanut_license_key_column_ready';
    public const SWEEP_HOOK = 'peanut_license_key_sweep';
    public const SWEEP_BATCH = 200;

    private const AD = 'peanut-license-server/license_key/v1';
    private const INFO_ENC = 'peanut-license-server/v1/key-encryption';
    private const INFO_MAC = 'peanut-license-server/v1/key-lookup';
    private const CANARY_PLAINTEXT = 'peanut-license-key-vault-canary';

    /**
     * Test seam: overrides the configured secret. false = force "no secret".
     *
     * @var string|false|null
     */
    public static $test_secret = null;

    /** @var array{0:string,1:string}|null|false cached [enc, mac] keys; false = unavailable */
    private static $keys = null;

    /**
     * Whether keys are encrypted and hashed with the secret.
     */
    public static function enabled(): bool {
        return self::keys() !== null;
    }

    /**
     * Forget cached subkeys (tests, secret rotation within one request).
     */
    public static function reset(): void {
        self::$keys = null;
    }

    /**
     * Whether a stored value is vault ciphertext.
     */
    public static function is_encrypted(?string $stored): bool {
        return is_string($stored) && strncmp($stored, self::PREFIX, strlen(self::PREFIX)) === 0;
    }

    /**
     * Normalize a key the way it is issued: trimmed, uppercase.
     */
    public static function normalize(string $key): string {
        return strtoupper(trim($key));
    }

    /**
     * Lookup hash written for new and swept rows.
     */
    public static function lookup_hash(string $key): string {
        $keys = self::keys();
        if ($keys === null) {
            return self::legacy_hash($key);
        }
        return hash_hmac('sha256', self::normalize($key), $keys[1]);
    }

    /**
     * Pre-vault lookup hash (unkeyed SHA-256). Still matched during migration.
     */
    public static function legacy_hash(string $key): string {
        return hash('sha256', self::normalize($key));
    }

    /**
     * Every hash a row for this key may currently carry, newest first.
     *
     * @return string[]
     */
    public static function candidate_hashes(string $key): array {
        return array_values(array_unique([self::lookup_hash($key), self::legacy_hash($key)]));
    }

    /**
     * Value to store in the license_key column.
     */
    public static function seal(string $key): string {
        $keys = self::keys();
        if ($keys === null) {
            return $key;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($key, self::AD, $nonce, $keys[0]);
        return self::PREFIX . base64_encode($nonce . $ct);
    }

    /**
     * Plaintext key for a stored value. Plaintext passes through unchanged;
     * ciphertext that cannot be opened (missing or wrong secret) returns null.
     */
    public static function reveal(?string $stored): ?string {
        if ($stored === null || $stored === '') {
            return $stored;
        }
        if (!self::is_encrypted($stored)) {
            return $stored;
        }
        $keys = self::keys();
        if ($keys === null) {
            return null;
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        $nlen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($raw === false || strlen($raw) <= $nlen) {
            return null;
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($raw, $nlen), self::AD, substr($raw, 0, $nlen), $keys[0]
        );
        return $plain === false ? null : $plain;
    }

    /**
     * Replace ->license_key on a row (or rows) with the plaintext key.
     * Unopenable ciphertext becomes '' so it is never displayed or emailed.
     *
     * @param object|array|null $rows
     * @return object|array|null
     */
    public static function reveal_rows($rows) {
        if (is_array($rows)) {
            foreach ($rows as $row) {
                self::reveal_rows($row);
            }
            return $rows;
        }
        if (is_object($rows) && property_exists($rows, 'license_key')) {
            $rows->license_key = self::reveal($rows->license_key) ?? '';
        }
        return $rows;
    }

    /**
     * Verify the configured secret is the one existing ciphertext was made
     * with. The first successful call records a canary; afterwards a mismatch
     * means the secret changed and swept rows can no longer be read.
     *
     * @return string 'disabled' | 'ok' | 'mismatch'
     */
    public static function secret_status(): string {
        if (!self::enabled()) {
            return 'disabled';
        }
        $canary = get_option(self::CANARY_OPTION, '');
        if (!is_string($canary) || $canary === '') {
            update_option(self::CANARY_OPTION, self::seal(self::CANARY_PLAINTEXT), false);
            return 'ok';
        }
        return self::reveal($canary) === self::CANARY_PLAINTEXT ? 'ok' : 'mismatch';
    }

    /**
     * Encrypt and rehash up to $limit legacy rows. Refuses to run when the
     * secret does not match the canary, so a mistyped secret cannot split
     * the table across two keys.
     *
     * @return int rows converted
     */
    public static function sweep(int $limit = self::SWEEP_BATCH): int {
        global $wpdb;
        if (self::secret_status() !== 'ok') {
            return 0;
        }
        $licenses = $wpdb->prefix . 'peanut_licenses';
        $logs = $wpdb->prefix . 'peanut_validation_logs';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, license_key FROM {$licenses} WHERE license_key NOT LIKE %s LIMIT %d",
            $wpdb->esc_like(self::PREFIX) . '%',
            $limit
        ));

        $done = 0;
        foreach ((array) $rows as $row) {
            $plain = (string) $row->license_key;
            if ($plain === '') {
                continue;
            }
            $updated = $wpdb->update(
                $licenses,
                ['license_key' => self::seal($plain), 'license_key_hash' => self::lookup_hash($plain)],
                ['id' => (int) $row->id, 'license_key' => $plain],
                ['%s', '%s'],
                ['%d', '%s']
            );
            if ($updated) {
                $done++;
                // Validation logs carry the lookup hash; move them to the keyed one.
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$logs} SET license_key_hash = %s WHERE license_key_hash = %s",
                    self::lookup_hash($plain),
                    self::legacy_hash($plain)
                ));
            }
        }
        return $done;
    }

    /**
     * Rows still stored as plaintext.
     */
    public static function remaining(): int {
        global $wpdb;
        $licenses = $wpdb->prefix . 'peanut_licenses';
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$licenses} WHERE license_key NOT LIKE %s AND license_key <> ''",
            $wpdb->esc_like(self::PREFIX) . '%'
        ));
    }

    /**
     * Wire the background sweep and the Site Health test.
     */
    public static function init(): void {
        add_action(self::SWEEP_HOOK, [self::class, 'run_scheduled_sweep']);
        add_filter('site_status_tests', [self::class, 'register_site_health_test']);
        if (self::enabled() && !wp_next_scheduled(self::SWEEP_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::SWEEP_HOOK);
        }
    }

    /**
     * Cron callback: convert a few batches, unschedule once nothing is left.
     */
    public static function run_scheduled_sweep(): void {
        for ($i = 0; $i < 10; $i++) {
            if (self::sweep() < self::SWEEP_BATCH) {
                break;
            }
        }
        if (self::secret_status() === 'ok' && self::remaining() === 0) {
            wp_clear_scheduled_hook(self::SWEEP_HOOK);
        }
    }

    /**
     * @param array $tests
     * @return array
     */
    public static function register_site_health_test(array $tests): array {
        $tests['direct']['peanut_license_key_vault'] = [
            'label' => __('License key encryption', 'peanut-license-server'),
            'test'  => [self::class, 'site_health_test'],
        ];
        return $tests;
    }

    public static function site_health_test(): array {
        $status = self::secret_status();
        $result = [
            'label'       => __('License keys are encrypted at rest', 'peanut-license-server'),
            'status'      => 'good',
            'badge'       => ['label' => __('Security', 'peanut-license-server'), 'color' => 'blue'],
            'description' => '',
            'test'        => 'peanut_license_key_vault',
        ];
        if ($status === 'disabled') {
            $result['status'] = 'recommended';
            $result['label'] = __('License keys are stored unencrypted', 'peanut-license-server');
            $result['description'] = sprintf(
                /* translators: %s: constant name */
                __('Define %s in wp-config.php (base64 of 32 random bytes) to encrypt license keys. Keep a copy of the secret outside the server: losing it makes every key unrecoverable.', 'peanut-license-server'),
                self::SECRET_CONSTANT
            );
        } elseif ($status === 'mismatch') {
            $result['status'] = 'critical';
            $result['label'] = __('License key secret does not match stored keys', 'peanut-license-server');
            $result['description'] = sprintf(
                __('%s changed after keys were encrypted. Encrypted keys cannot be read and licenses cannot be validated until the original secret is restored.', 'peanut-license-server'),
                self::SECRET_CONSTANT
            );
        } elseif (($left = self::remaining()) > 0) {
            $result['status'] = 'recommended';
            $result['label'] = sprintf(
                /* translators: %d: number of licenses */
                _n('%d license key is still waiting to be encrypted', '%d license keys are still waiting to be encrypted', $left, 'peanut-license-server'),
                $left
            );
            $result['description'] = __('The hourly sweep will finish this, or run: wp peanut-license encrypt-keys', 'peanut-license-server');
        }
        return $result;
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private static function keys(): ?array {
        if (self::$keys === null) {
            self::$keys = self::derive() ?? false;
        }
        return self::$keys === false ? null : self::$keys;
    }

    private static function derive(): ?array {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            return null;
        }
        // Ciphertext is 85 chars; sealing into the old VARCHAR(64) would
        // truncate it and destroy the key.
        if (get_option(self::COLUMN_READY_OPTION) !== '1') {
            return null;
        }
        $secret = self::$test_secret;
        if ($secret === null) {
            $secret = defined(self::SECRET_CONSTANT) ? constant(self::SECRET_CONSTANT) : getenv(self::SECRET_CONSTANT);
        }
        if (!is_string($secret) || $secret === '') {
            return null;
        }
        $ikm = base64_decode($secret, true);
        if ($ikm === false || strlen($ikm) < 32) {
            if (class_exists('Peanut_Logger')) {
                Peanut_Logger::error(self::SECRET_CONSTANT . ' must be base64 of at least 32 bytes; encryption disabled');
            }
            return null;
        }
        return [
            hash_hkdf('sha256', $ikm, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, self::INFO_ENC),
            hash_hkdf('sha256', $ikm, 32, self::INFO_MAC),
        ];
    }
}
