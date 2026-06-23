<?php
/**
 * DB Migrations Unit Tests
 *
 * Tests the always-on migration self-heal: version gating (cheap path),
 * drift detection (option says current but a column is missing), and the
 * idempotent re-run of create_tables() as a safety net for ALL tables.
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A $wpdb spy that lets a test script the schema-presence answers and records
 * which DDL/queries ran, so we can assert the cheap-path / drift-path behaviour
 * without a real database.
 */
class MigrationsSpyWPDB extends MockWPDB {
    /** @var array<string,bool> column "table.column" => exists? */
    public array $present = [];
    /** @var string[] */
    public array $queries = [];
    public int $create_tables_calls = 0;

    public function get_var(?string $query = null) {
        // INFORMATION_SCHEMA.COLUMNS drift probe: "... TABLE_NAME = 'x' ... COLUMN_NAME = 'y'"
        if ($query !== null && stripos($query, 'INFORMATION_SCHEMA.COLUMNS') !== false) {
            if (preg_match("/TABLE_NAME = '([^']+)'.*COLUMN_NAME = '([^']+)'/s", $query, $m)) {
                $key = $m[1] . '.' . $m[2];
                return !empty($this->present[$key]) ? 1 : 0;
            }
        }
        return null;
    }

    public function query(string $query) {
        $this->queries[] = $query;
        return true;
    }
}

/**
 * @covers Peanut_License_DB_Migrations
 */
class DbMigrationsTest extends TestCase {

    private MigrationsSpyWPDB $spy;
    private $origWpdb;

    protected function setUp(): void {
        parent::setUp();
        PeanutTestHelper::clearOptions();
        global $wpdb;
        $this->origWpdb = $wpdb;
        $this->spy = new MigrationsSpyWPDB();
        $wpdb = $this->spy;
        $GLOBALS['wpdb'] = $this->spy;
        // Reset the static create_tables hook counter.
        Peanut_License_DB_Migrations::$test_create_tables = static function (): void {
            global $wpdb;
            $wpdb->create_tables_calls++;
        };
    }

    protected function tearDown(): void {
        global $wpdb;
        $wpdb = $this->origWpdb;
        $GLOBALS['wpdb'] = $this->origWpdb;
        Peanut_License_DB_Migrations::$test_create_tables = null;
        PeanutTestHelper::clearOptions();
        parent::tearDown();
    }

    /**
     * @test
     * Cheap path: option already at current version AND schema present ->
     * no DDL, no create_tables() re-run, just the single option read.
     */
    public function current_version_with_intact_schema_does_no_work(): void {
        update_option('peanut_license_server_db_version', Peanut_License_DB_Migrations::DB_VERSION);
        $this->markAllColumnsPresent();

        Peanut_License_DB_Migrations::check_db_version();

        $this->assertSame(0, $this->spy->create_tables_calls, 'create_tables must NOT run on the hot path when current');
        $this->assertSame([], $this->spy->queries, 'no ALTER/DDL should run when current and intact');
    }

    /**
     * @test
     * Stale version (fresh upgrade) -> migrations run + create_tables() re-run.
     */
    public function stale_version_triggers_migration_and_create_tables(): void {
        update_option('peanut_license_server_db_version', '1.4.0');
        $this->markAllColumnsPresent();

        Peanut_License_DB_Migrations::check_db_version();

        $this->assertSame(1, $this->spy->create_tables_calls, 'create_tables safety-net must run on upgrade');
        $this->assertSame(
            Peanut_License_DB_Migrations::DB_VERSION,
            get_option('peanut_license_server_db_version'),
            'db version option must be advanced after migration'
        );
    }

    /**
     * @test
     * Drift: option claims current but a tracked column is missing -> re-run.
     * This is the headless/API-only trap connect's self-heal was built for.
     */
    public function schema_drift_at_current_version_still_self_heals(): void {
        update_option('peanut_license_server_db_version', Peanut_License_DB_Migrations::DB_VERSION);
        $this->markAllColumnsPresent();
        // Knock out one tracked column to simulate drift.
        global $wpdb;
        $table = $wpdb->prefix . 'peanut_activations';
        $this->spy->present[$table . '.deactivated_at'] = false;

        Peanut_License_DB_Migrations::check_db_version();

        $this->assertSame(1, $this->spy->create_tables_calls, 'drift must force a create_tables re-run even at current version');
    }

    /**
     * @test
     * The new migrations trigger is hooked on an always-on early hook so it
     * fires on REST/AJAX/cron requests, not only wp-admin page loads.
     */
    public function migration_check_is_hooked_on_an_always_on_hook(): void {
        global $_mock_actions;
        $_mock_actions = [];
        Peanut_License_DB_Migrations::init();
        $hooks = array_keys($_mock_actions ?? []);
        $this->assertTrue(
            in_array('plugins_loaded', $hooks, true) || in_array('init', $hooks, true),
            'migration check must hook plugins_loaded or init, not admin_init'
        );
    }

    private function markAllColumnsPresent(): void {
        global $wpdb;
        foreach (Peanut_License_DB_Migrations::tracked_columns() as $table => $cols) {
            foreach ($cols as $col) {
                $this->spy->present[$table . '.' . $col] = true;
            }
        }
    }
}
