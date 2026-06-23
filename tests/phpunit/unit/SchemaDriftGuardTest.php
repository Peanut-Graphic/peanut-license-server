<?php
/**
 * Schema Drift Guard (CI).
 *
 * Statically asserts that every column written via $wpdb->insert()/->update()
 * to a peanut_* custom table actually exists in that table's schema — the union
 * of its CREATE TABLE (dbDelta) columns plus any migration-added columns
 * tracked in Peanut_License_DB_Migrations::tracked_columns().
 *
 * This is the structural net behind fixes A and B: it would have caught the
 * site-health/deactivated_at writes hitting columns the DB lacked, and the
 * ENUM clamp keeps the validation-logger 'status' write legal. It also fails if
 * a future insert/update references a column that no CREATE TABLE / migration
 * defines.
 *
 * Resolution is tuned to this codebase's conventions:
 *   - table expr: $wpdb->prefix . 'peanut_x'
 *   - table expr: {$var} / {$table} where $var was assigned one of the above
 *     or a get_*()/get_*_table() accessor
 *   - table expr: self::get_*_table() / self::get_table_name() accessors whose
 *     body returns $wpdb->prefix . 'peanut_x'
 * If a write site can't be resolved to a known peanut_* table, it is reported
 * (so a new pattern can't silently slip past the guard).
 *
 * @package Peanut_License_Server
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class SchemaDriftGuardTest extends TestCase {

    /** @var string[] absolute paths of source files scanned. */
    private array $files = [];

    /** @var array<string,array<string,string>> file => (accessor-name => peanut_* suffix) */
    private array $accessorsByFile = [];

    /** @var array<string,string> accessor-name => peanut_* suffix (cross-file, for CREATE blocks) */
    private array $accessors = [];

    /** @var array<string,array<string,bool>> peanut_suffix => set of columns */
    private array $schema = [];

    protected function setUp(): void {
        parent::setUp();
        $root = PEANUT_LICENSE_SERVER_PATH;
        $this->files = array_merge(
            glob($root . 'includes/*.php') ?: [],
            [$root . 'peanut-license-server.php']
        );

        $this->indexAccessors();
        $this->indexSchemas();
        $this->mergeTrackedColumns();
    }

    /**
     * @test
     * Every column written via insert()/update() to a peanut_* table exists in
     * that table's schema.
     */
    public function every_written_column_exists_in_its_table_schema(): void {
        $this->assertNotEmpty($this->schema, 'failed to parse any peanut_* CREATE TABLE schema');

        $violations = [];
        $unresolved = [];

        foreach ($this->files as $file) {
            $src = file_get_contents($file);
            foreach ($this->findWriteSites($src) as $site) {
                $table = $this->resolveTable($site['table_expr'], $src, $file);
                if ($table === null) {
                    // Only flag sites that clearly target a peanut_* table.
                    if (stripos($site['table_expr'], 'peanut_') !== false
                        || preg_match('/get_[a-z_]*table|get_table_name/i', $site['table_expr'])) {
                        $unresolved[] = basename($file) . ': ' . trim($site['table_expr']);
                    }
                    continue;
                }
                if (!isset($this->schema[$table])) {
                    continue; // not a peanut_* table we own
                }
                foreach ($site['columns'] as $col) {
                    if (!isset($this->schema[$table][$col])) {
                        $violations[] = sprintf(
                            '%s writes column "%s" to %s, which is NOT in its schema',
                            basename($file),
                            $col,
                            $table
                        );
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $unresolved,
            "Unresolved peanut_* write sites (extend the resolver):\n" . implode("\n", $unresolved)
        );
        $this->assertSame(
            [],
            $violations,
            "Schema drift — columns written that don't exist in the table schema:\n" . implode("\n", $violations)
        );
    }

    // ---- indexing ---------------------------------------------------------

    /** Map accessor methods (get_*_table / get_table_name) to a peanut_ suffix. */
    private function indexAccessors(): void {
        foreach ($this->files as $file) {
            $src = file_get_contents($file);
            if (preg_match_all(
                '/function\s+(get_[a-z_]*table|get_table_name)\s*\([^)]*\)\s*:\s*string\s*\{(.*?)\}/s',
                $src,
                $matches,
                PREG_SET_ORDER
            )) {
                foreach ($matches as $m) {
                    $name = $m[1];
                    if (preg_match("/return\s+\\\$wpdb->prefix\s*\.\s*'([a-z_]*peanut_[a-z_]+)'/", $m[2], $rm)) {
                        $suffix = $this->normalize($rm[1]);
                        // Per-file map: self::get_table_name() means *this* class's
                        // accessor, even though the name collides across files.
                        $this->accessorsByFile[$file][$name] = $suffix;
                        // Cross-file map only for unambiguous (single-definition)
                        // accessor names — used to resolve CREATE TABLE blocks.
                        if (!array_key_exists($name, $this->accessors)) {
                            $this->accessors[$name] = $suffix;
                        } elseif ($this->accessors[$name] !== $suffix) {
                            $this->accessors[$name] = false; // ambiguous
                        }
                    }
                }
            }
        }
    }

    /** Parse CREATE TABLE blocks into peanut_suffix => column-set. */
    private function indexSchemas(): void {
        foreach ($this->files as $file) {
            $src = file_get_contents($file);
            if (!preg_match_all(
                '/CREATE TABLE(?: IF NOT EXISTS)?\s+(.+?)\s*\((.*?)\)\s*\{?\$?charset/si',
                $src,
                $matches,
                PREG_SET_ORDER
            )) {
                continue;
            }
            foreach ($matches as $m) {
                $table = $this->resolveTable($m[1], $src, $file);
                if ($table === null) {
                    continue;
                }
                $cols = $this->parseColumns($m[2]);
                $this->schema[$table] = ($this->schema[$table] ?? []) + array_fill_keys($cols, true);
            }
        }
    }

    /** Fold migration-added columns into the schema so drift-fixed cols count. */
    private function mergeTrackedColumns(): void {
        global $wpdb;
        foreach (Peanut_License_DB_Migrations::tracked_columns() as $table => $cols) {
            $suffix = $this->normalize(str_replace($wpdb->prefix, '', $table));
            foreach ($cols as $c) {
                $this->schema[$suffix][$c] = true;
            }
        }
    }

    // ---- parsing helpers --------------------------------------------------

    /** Extract column names from the body of a CREATE TABLE column list. */
    private function parseColumns(string $body): array {
        $cols = [];
        foreach (preg_split('/,\n/', $body) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Skip key/index/constraint definitions.
            if (preg_match('/^(PRIMARY|UNIQUE|KEY|INDEX|FOREIGN|CONSTRAINT|FULLTEXT)\b/i', $line)) {
                continue;
            }
            if (preg_match('/^`?([a-z_][a-z0-9_]*)`?\s+[A-Z]/i', $line, $m)) {
                $cols[] = strtolower($m[1]);
            }
        }
        return $cols;
    }

    /**
     * Find $wpdb->insert(<table>, [<data>], ...) and
     * $wpdb->update(<table>, [<data>], [<where>], ...) sites and pull the
     * literal-string array keys (data + where) plus the raw table expression.
     *
     * @return array<int,array{table_expr:string,columns:string[]}>
     */
    private function findWriteSites(string $src): array {
        $sites = [];
        $len = strlen($src);
        $offset = 0;
        while (preg_match('/\$wpdb->(insert|update)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $start = $m[0][1] + strlen($m[0][0]);
            $args = $this->captureArgs($src, $start, $len);
            $offset = $start;
            if ($args === null) {
                continue;
            }
            [$tableExpr, $arrayArgs] = $args;
            $cols = [];
            foreach ($arrayArgs as $arr) {
                // Inline array literal, or a bare $var pointing at one.
                if (!str_starts_with($arr, '[')) {
                    if (preg_match('/^\$([a-z_][a-z0-9_]*)$/i', $arr, $vm)) {
                        $arr = $this->traceArrayVar($src, $vm[1]) ?? $arr;
                    }
                }
                foreach ($this->arrayKeys($arr) as $k) {
                    $cols[$k] = true;
                }
            }
            $sites[] = ['table_expr' => $tableExpr, 'columns' => array_keys($cols)];
        }
        return $sites;
    }

    /**
     * From just after the opening "(", capture the first argument (table expr)
     * and any array-literal arguments (data, where) up to the matching ")".
     *
     * @return array{0:string,1:string[]}|null
     */
    private function captureArgs(string $src, int $pos, int $len): ?array {
        $depth = 1;
        $argStart = $pos;
        $topArgs = [];
        for ($i = $pos; $i < $len; $i++) {
            $ch = $src[$i];
            if ($ch === '(' || $ch === '[') {
                $depth++;
            } elseif ($ch === ')' ) {
                $depth--;
                if ($depth === 0) {
                    $topArgs[] = substr($src, $argStart, $i - $argStart);
                    break;
                }
            } elseif ($ch === ']') {
                $depth--;
            } elseif ($ch === ',' && $depth === 1) {
                $topArgs[] = substr($src, $argStart, $i - $argStart);
                $argStart = $i + 1;
            }
        }
        if (empty($topArgs)) {
            return null;
        }
        $tableExpr = trim($topArgs[0]);
        // The data array is arg #2; for update(), the where array is arg #3.
        // Format-spec args (#3/#4) are numeric arrays with no string keys, so
        // arrayKeys() naturally ignores them — we can safely consider args 2-3.
        $candidates = [];
        foreach (array_slice($topArgs, 1, 2) as $a) {
            $a = trim($a);
            if ($a !== '') {
                $candidates[] = $a;
            }
        }
        return [$tableExpr, $candidates];
    }

    /** Trace `$var = [ ... ];` to its array-literal source within $src. */
    private function traceArrayVar(string $src, string $var): ?string {
        if (preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*(\[)/', $src, $m, PREG_OFFSET_CAPTURE)) {
            $open = $m[1][1];
            $depth = 0;
            for ($i = $open, $n = strlen($src); $i < $n; $i++) {
                if ($src[$i] === '[') {
                    $depth++;
                } elseif ($src[$i] === ']') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($src, $open, $i - $open + 1);
                    }
                }
            }
        }
        return null;
    }

    /** Pull literal string keys ('col' => ...) from an array-literal source. */
    private function arrayKeys(string $arr): array {
        $keys = [];
        if (preg_match_all("/'([a-z_][a-z0-9_]*)'\s*=>/i", $arr, $m)) {
            foreach ($m[1] as $k) {
                $keys[] = strtolower($k);
            }
        }
        return $keys;
    }

    /**
     * Resolve a table expression to a normalized peanut_* suffix, or null.
     */
    private function resolveTable(string $expr, string $src, string $file): ?string {
        $expr = trim($expr);

        // self::get_*_table() / static::get_table_name() -> THIS file's accessor.
        if (preg_match('/(?:self|static)::(get_[a-z_]*table|get_table_name)\s*\(/i', $expr, $m)) {
            return $this->accessorsByFile[$file][$m[1]] ?? null;
        }

        // Cross-class accessor reference, e.g. Peanut_License_Manager::get_table_name()
        if (preg_match('/::(get_[a-z_]*table|get_table_name)\s*\(/i', $expr, $m)) {
            $val = $this->accessors[$m[1]] ?? null;
            return is_string($val) ? $val : null; // false = ambiguous
        }

        // $wpdb->prefix . 'peanut_x'
        if (preg_match("/\\\$wpdb->prefix\s*\.\s*'([a-z_]*peanut_[a-z_]+)'/", $expr, $m)) {
            return $this->normalize($m[1]);
        }

        // {$wpdb->prefix}peanut_x  (interpolated)
        if (preg_match("/\{\\\$wpdb->prefix\}([a-z_]*peanut_[a-z_]+)/", $expr, $m)) {
            return $this->normalize($m[1]);
        }

        // {$var} or {$table} or plain $var -> trace the local assignment.
        if (preg_match('/\{?\$([a-z_][a-z0-9_]*)\}?/i', $expr, $m)) {
            $var = $m[1];
            // $var = self::get_*();
            if (preg_match('/\$' . preg_quote($var, '/') . '\s*=\s*(?:self|static)::(get_[a-z_]*table|get_table_name)\s*\(/i', $src, $am)) {
                return $this->accessorsByFile[$file][$am[1]] ?? null;
            }
            // $var = $wpdb->prefix . 'peanut_x';
            if (preg_match('/\$' . preg_quote($var, '/') . "\s*=\s*\\\$wpdb->prefix\s*\.\s*'([a-z_]*peanut_[a-z_]+)'/", $src, $am)) {
                return $this->normalize($am[1]);
            }
        }

        return null;
    }

    /** Strip a possible leading wp_ prefix and lowercase. */
    private function normalize(string $name): string {
        $name = strtolower($name);
        if (str_starts_with($name, 'wp_')) {
            $name = substr($name, 3);
        }
        return $name;
    }
}
