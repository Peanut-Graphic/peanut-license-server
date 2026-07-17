<?php
/**
 * Ed25519 entitlement signer (audit 2026-07, C1b).
 *
 * Signs the license/validate entitlement so a client can prove the tier it was
 * granted actually came from this server and was not forged or replayed. The
 * signature binds the entitlement to the requesting license key + site and a
 * short validity window. Private key lives in wp_options (autoload off).
 */

if (!defined('ABSPATH')) { exit; }

class Peanut_License_Signer {
    private const OPT_SK  = 'peanut_ls_sign_sk';
    private const OPT_PK  = 'peanut_ls_sign_pk';
    private const OPT_KID = 'peanut_ls_sign_kid';
    /** Entitlement validity window (seconds). Clients must reject stale signatures. */
    public const TTL = 900;

    private function ensure_keypair(): void {
        if (get_option(self::OPT_SK) && get_option(self::OPT_PK)) { return; }
        $kp = sodium_crypto_sign_keypair();
        $sk = sodium_crypto_sign_secretkey($kp);
        $pk = sodium_crypto_sign_publickey($kp);
        // autoload 'no' — the secret key must never be loaded on every request.
        add_option(self::OPT_SK, base64_encode($sk), '', 'no');
        add_option(self::OPT_PK, base64_encode($pk), '', 'no');
        add_option(self::OPT_KID, substr(hash('sha256', $pk), 0, 16), '', 'no');
        sodium_memzero($kp); sodium_memzero($sk);
    }

    /** Public key material for clients to pin. */
    public function public_key(): array {
        $this->ensure_keypair();
        return [
            'alg'        => 'ed25519',
            'kid'        => get_option(self::OPT_KID),
            'public_key' => get_option(self::OPT_PK), // base64
        ];
    }

    /**
     * Canonical bytes signed/verified. Deterministic: sort keys, no escaped
     * slashes. The client MUST reproduce this exactly.
     */
    private function canonical(array $payload): string {
        ksort($payload);
        return wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Build the signed entitlement block bound to this key + site. */
    public function sign_entitlement(array $license, string $license_key, ?string $site_url): array {
        $this->ensure_keypair();
        $payload = [
            'kid'        => get_option(self::OPT_KID),
            'issued_at'  => time(),
            'ttl'        => self::TTL,
            'key_hash'   => hash('sha256', $license_key),
            'site_url'   => $site_url,
            'status'     => $license['status']     ?? null,
            'tier'       => $license['tier']       ?? null,
            'expires_at' => $license['expires_at'] ?? null,
        ];
        $sk  = base64_decode(get_option(self::OPT_SK));
        $sig = sodium_crypto_sign_detached($this->canonical($payload), $sk);
        sodium_memzero($sk);
        $payload['signature'] = base64_encode($sig);
        return $payload;
    }

    /**
     * Verify a signed block against the expected key/site. Mirrors the client;
     * also used by tests. Returns true only if signature, binding and freshness
     * all hold.
     */
    public function verify(array $signed, string $license_key, ?string $site_url): bool {
        $this->ensure_keypair();
        $sig = base64_decode($signed['signature'] ?? '', true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) { return false; }
        $payload = $signed; unset($payload['signature']);
        $pk = base64_decode(get_option(self::OPT_PK));
        if (!sodium_crypto_sign_verify_detached($sig, $this->canonical($payload), $pk)) { return false; }
        if (!hash_equals(hash('sha256', $license_key), (string) ($signed['key_hash'] ?? ''))) { return false; }
        if ($site_url !== null && ($signed['site_url'] ?? null) !== $site_url) { return false; }
        if (time() > (int) ($signed['issued_at'] ?? 0) + (int) ($signed['ttl'] ?? 0)) { return false; }
        return true;
    }
}
