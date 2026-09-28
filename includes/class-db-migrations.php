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
 * This class runs the check during the plugin's always-on `init@0` boot
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
    const DB_VERSION = '1.8.0';

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
     * Run the always-on migration check during the plugin's init@0 boot.
     *
     * The root plugin calls this only after its singleton is fully assigned,
     * so a required create_tables() pass cannot recursively construct it.
     */
    public static function init(): void {
        self::check_db_version();
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

        // v1.7.0: license_key_hash is the only lookup column.
        self::backfill_license_key_hashes();
        self::ensure_unique_license_key_hash();

        // v1.8.0: license_key holds vault ciphertext (85 chars); uniqueness
        // lives on license_key_hash, since ciphertext is randomized.
        self::widen_license_key_column();

        // v1.6.0: full-table dbDelta safety net (covers tables that previously
        // had NO upgrade coverage at all — licenses, update_logs, validation
        // logs, webhook logs, audit, security, GDPR, bundles, affiliate).
        self::run_create_tables();
    }

    /**
     * Give every license a hash of its normalized key. Rows created before
     * v1.3.0 may have an empty hash, and any row whose hash disagrees with its
     * key would become unreachable once lookups stopped reading the plaintext
     * column. Idempotent: a correct row never matches the WHERE clause.
     */
    private static function backfill_license_key_hashes(): void {
        global $wpdb;
        $licenses = $wpdb->prefix . 'peanut_licenses';

        $wpdb->query("UPDATE {$licenses}
            SET license_key_hash = SHA2(UPPER(TRIM(license_key)), 256)
            WHERE license_key IS NOT NULL AND license_key <> ''
              AND (license_key_hash IS NULL OR license_key_hash = ''
                   OR license_key_hash <> SHA2(UPPER(TRIM(license_key)), 256))");
    }

    /**
     * Replace the plain idx_license_key_hash with a UNIQUE key so the hash
     * carries the uniqueness guarantee the plaintext column used to. Skips
     * (and logs) when duplicate hashes exist rather than failing the upgrade;
     * those rows need a human.
     */
    private static function ensure_unique_license_key_hash(): void {
        global $wpdb;
        $licenses = $wpdb->prefix . 'peanut_licenses';

        $non_unique = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(NON_UNIQUE) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'license_key_hash'",
            $wpdb->dbname ?? '',
            $licenses
        ));
        if ($non_unique !== null && (string) $non_unique === '0') {
            return; // Already unique.
        }

        $duplicates = (int) $wpdb->get_var("SELECT COUNT(*) FROM (
            SELECT license_key_hash FROM {$licenses}
            GROUP BY license_key_hash HAVING COUNT(*) > 1) AS dupes");
        if ($duplicates > 0) {
            if (class_exists('Peanut_Logger')) {
                Peanut_Logger::error('Duplicate license key hashes; unique index not added', [
                    'duplicate_hashes' => $duplicates,
                ]);
            }
            return;
        }

        $drop = $non_unique !== null ? 'DROP INDEX idx_license_key_hash, ' : '';
        $wpdb->query("ALTER TABLE {$licenses} {$drop}ADD UNIQUE KEY unique_license_key_hash (license_key_hash)");
    }

    /**
     * Widen license_key to VARCHAR(128) and drop its UNIQUE index. Only runs
     * once the hash is unique, so the table never loses its uniqueness
     * guarantee.
     */
    private static function widen_license_key_column(): void {
        global $wpdb;
        $licenses = $wpdb->prefix . 'peanut_licenses';

        $hash_non_unique = $wpdb->get_var($wpdb->prepare(
            "SELECT MIN(NON_UNIQUE) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'license_key_hash'",
            $wpdb->dbname ?? '',
            $licenses
        ));
        if ($hash_non_unique === null || (string) $hash_non_unique !== '0') {
            if (class_exists('Peanut_Logger')) {
                Peanut_Logger::error('license_key_hash is not unique; license_key left as-is (encryption stays off until resolved)');
            }
            return;
        }

        $length = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'license_key'",
            $wpdb->dbname ?? '',
            $licenses
        ));
        if ($length > 0 && $length < 128) {
            $wpdb->query("ALTER TABLE {$licenses} MODIFY license_key VARCHAR(128) NOT NULL");
        }

        $has_unique = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'unique_license_key'",
            $wpdb->dbname ?? '',
            $licenses
        ));
        if ((int) $has_unique > 0) {
            $wpdb->query("ALTER TABLE {$licenses} DROP INDEX unique_license_key");
        }

        if (self::license_key_column_ready()) {
            update_option(Peanut_License_Key_Vault::COLUMN_READY_OPTION, '1');
        }
    }

    /**
     * Whether license_key can hold vault ciphertext.
     */
    public static function license_key_column_ready(): bool {
        global $wpdb;
        $length = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'license_key'",
            $wpdb->dbname ?? '',
            $wpdb->prefix . 'peanut_licenses'
        ));
        return $length >= 128;
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
