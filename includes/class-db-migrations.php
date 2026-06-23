<?php
/**
 * Database Migrations / Schema Self-Heal
 *
 * The license server is hit by REST (update-check, activation, site-health),
 * admin-ajax downloads, and WP-Cron — none of which run on `admin_init`. The
 * old migration trigger was hooked on `admin_init`, so on an upgraded,
 * API-only/headless server new code could write columns the DB lacked until a
 * human happened to open wp-admin. Auto-update doesn't re-run the activation
 * hook either.
 *
 * This class moves the check to an always-on early hook (`plugins_loaded`)
 * behind a fast `get_option` version gate, and — mirroring peanut-connect's
 * check_db_version() self-heal — re-runs create_tables() (dbDelta) for ALL
 * tables as an idempotent safety net, plus drift detection so a mismatched
 * option can never trap us in a permanently-broken schema.
 *
 * Cheap on the hot path: when the stored version already matches DB_VERSION
 * AND the tracked columns exist, the only work is a single option read plus a
 * handful of INFORMATION_SCHEMA COUNT(*) probes (one per tracked column).
 *
 * @package Peanut_License_Server
 */

defined('ABSPATH') || exit;

class Peanut_License_DB_Migrations {

    /**
     * Current schema version. BUMP THIS whenever create_tables() or a
     * migration changes the schema, and add any new columns to
     * tracked_columns() below so drift detection covers them.
     */
    const DB_VERSION = '1.6.0';

    /** Option key holding the installed schema version. */
    const DB_VERSION_OPTION = 'peanut_license_server_db_version';

    /**
     * Test seam: when set, called instead of the real create_tables(). Lets the
     * unit suite assert the safety-net re-run without booting the whole plugin
     * or owning a real database. Production leaves this null.
     *
     * @var callable|null
     */
    public static $test_create_tables = null;

    /**
     * Register the always-on migration check.
     *
     * `plugins_loaded` fires for every request type (web, REST, AJAX, cron),
     * unlike `admin_init` which only fires inside wp-admin. Priority 5 so the
     * schema is healed before `init`-time code that writes to these tables.
     */
    public static function init(): void {
        add_action('plugins_loaded', [__CLASS__, 'check_db_version'], 5);
    }

    /**
     * Columns introduced after the original CREATE TABLE, keyed by table name.
     * Drift detection probes each; a missing one forces a self-heal even when
     * the version option claims we're current. Keep in sync with create_tables()
     * and the migration ladder in run_migrations().
     *
     * @return array<string,string[]>
     */
    public static function tracked_columns(): array {
        global $wpdb;
        $activations = $wpdb->prefix . 'peanut_activations';
        return [
            $activations => [
                'wp_version',
                'php_version',
                'is_multisite',
                'active_plugins',
                'health_status',
                'health_errors',
                'deactivated_at',
            ],
        ];
    }

    /**
     * Decide whether a migration is needed, and run it if so.
     *
     * Two triggers (same logic as peanut-connect's self-heal):
     *   1. Stored version != DB_VERSION (normal upgrade).
     *   2. Stored version == DB_VERSION but a tracked column is missing
     *      (drift / partial-migration). Trusting the option without
     *      verifying the schema is a one-way trap: once wrong, you stay
     *      wrong and every write to the missing column fails forever.
     */
    public static function check_db_version(): void {
        $installed = get_option(self::DB_VERSION_OPTION, '1.0.0');

        $needs_migration = version_compare((string) $installed, self::DB_VERSION, '<')
            || !self::schema_matches_current_version();

        if (!$needs_migration) {
            return; // Hot path: nothing to do.
        }

        self::run_migrations((string) $installed);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
    }

    /**
     * Verify every tracked column exists. Returns false on the first missing
     * column (drift). One INFORMATION_SCHEMA COUNT(*) per column.
     */
    private static function schema_matches_current_version(): bool {
        global $wpdb;

        foreach (self::tracked_columns() as $table => $columns) {
            foreach ($columns as $column) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                    $wpdb->dbname ?? '',
                    $table,
                    $column
                ));
                if (!$exists) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Run the migration ladder, then re-run create_tables() as an idempotent
     * safety net for ALL tables (dbDelta no-ops when the schema already
     * matches). The column ALTERs below are belt-and-suspenders for installs
     * whose create_tables() predates these columns; create_tables() alone is
     * insufficient because it uses CREATE TABLE IF NOT EXISTS, which never
     * touches an existing table's columns.
     *
     * @param string $previous_version The version recorded BEFORE this run.
     */
    private static function run_migrations(string $previous_version): void {
        global $wpdb;
        $activations = $wpdb->prefix . 'peanut_activations';

        // v1.4.0: health columns on activations.
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$activations} LIKE 'health_status'"))) {
            $wpdb->query("ALTER TABLE {$activations}
                ADD COLUMN wp_version VARCHAR(20) DEFAULT NULL AFTER plugin_version,
                ADD COLUMN php_version VARCHAR(20) DEFAULT NULL AFTER wp_version,
                ADD COLUMN is_multisite TINYINT(1) DEFAULT 0 AFTER php_version,
                ADD COLUMN active_plugins INT DEFAULT 0 AFTER is_multisite,
                ADD COLUMN health_status ENUM('healthy', 'warning', 'critical', 'offline') DEFAULT 'healthy' AFTER active_plugins,
                ADD COLUMN health_errors TEXT DEFAULT NULL AFTER health_status,
                ADD INDEX idx_health_status (health_status)
            ");
        }

        // v1.5.0: deactivated_at on activations.
        if (empty($wpdb->get_results("SHOW COLUMNS FROM {$activations} LIKE 'deactivated_at'"))) {
            $wpdb->query("ALTER TABLE {$activations}
                ADD COLUMN deactivated_at DATETIME DEFAULT NULL AFTER last_checked
            ");
        }

        // v1.6.0: full-table dbDelta safety net (covers tables that previously
        // had NO upgrade coverage at all — licenses, update_logs, validation
        // logs, webhook logs, audit, security, GDPR, bundles, affiliate).
        self::run_create_tables();
    }

    /**
     * Invoke the plugin's create_tables() (or the test seam).
     */
    private static function run_create_tables(): void {
        if (is_callable(self::$test_create_tables)) {
            call_user_func(self::$test_create_tables);
            return;
        }
        if (class_exists('Peanut_License_Server')) {
            Peanut_License_Server::get_instance()->ensure_tables();
        }
    }
}
