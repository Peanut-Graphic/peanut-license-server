<?php
/** Explicit operator provisioning for read-only entitlement credentials. */
defined('ABSPATH') || exit;
class Peanut_Projection_CLI {
    /**
     * Provision a read-only credential; rotation requires the same binding.
     *
     * ## OPTIONS
     * <account>
     * : Stable source account label assigned by the operator.
     * <agency>
     * : Explicit HUB agency ID.
     * <license-ids>
     * : Comma-separated existing entitlement IDs assigned to this account.
     * [--rotate]
     * : Explicitly replace an existing token.
     */
    public function provision($args, $assoc_args): void {
        if (count($args) !== 3 || !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $args[2])) { WP_CLI::error('Provide account, agency ID and comma-separated license IDs.'); return; }
        try {
            $token = Peanut_Entitlement_Projection::provision($args[0], $args[1], array_map('intval', explode(',', $args[2])), isset($assoc_args['rotate']));
            WP_CLI::warning('Copy securely now. This token is read-only; only its hash is stored.'); WP_CLI::line($token);
        } catch (Throwable $error) { WP_CLI::error($error->getMessage()); }
    }
    /**
     * Revoke a projection credential without changing licenses.
     *
     * ## OPTIONS
     * <account>
     * : Stable source account label.
     */
    public function revoke($args): void {
        if (count($args) !== 1) { WP_CLI::error('Provide one source account.'); return; }
        try { Peanut_Entitlement_Projection::revoke($args[0]); WP_CLI::success('Projection token revoked; source licenses unchanged.'); }
        catch (Throwable $error) { WP_CLI::error($error->getMessage()); }
    }
}
WP_CLI::add_command('peanut-license projection', Peanut_Projection_CLI::class);
