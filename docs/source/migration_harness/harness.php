<?php
/**
 * Offline migration harness for Pluto ERP.
 *
 * Loads strict stand-ins for the parts of Laravel's schema builder the generated migrations use
 * (any other method throws), then:
 *   1. runs every migration's up() in filename order, asserting each FK target table already exists;
 *   2. records the resulting physical model (columns with MySQL COLUMN_TYPE per Laravel's MySqlGrammar,
 *      keys, FKs, CHECK names) to the JSON file given as argv[2];
 *   3. runs every down() in reverse order, asserting no remaining table still references a dropped one.
 *
 * Usage: php harness.php <migrations-dir> <out.json>
 * This does not replace running the migrations on MySQL 8.0 (see SchemaContractTest); it proves the files
 * are valid PHP, use only real Laravel APIs, are ordered correctly and express exactly the documented schema.
 */

namespace Illuminate\Database\Migrations {
    abstract class Migration {}
}

namespace Illuminate\Database\Schema {
    final class HarnessError extends \RuntimeException {}

    final class ColumnDefinition
    {
        public array $a;
        public function __construct(array $attrs) { $this->a = $attrs + ['nullable' => false, 'default' => null, 'comment' => null, 'storedAs' => null, 'primary' => false]; }
        public function nullable(bool $value = true): static { $this->a['nullable'] = $value; return $this; }
        public function default($value): static { $this->a['default'] = $value; $this->a['has_default'] = true; return $this; }
        public function comment(string $comment): static { $this->a['comment'] = $comment; return $this; }
        public function storedAs(string $expression): static { $this->a['storedAs'] = $expression; return $this; }
        public function primary(bool $value = true): static { $this->a['primary'] = $value; return $this; }
        public function __call($m, $args) { throw new HarnessError("ColumnDefinition::$m() is not used by Pluto migrations"); }
    }

    final class ForeignKeyDefinition
    {
        public array $a;
        public function __construct(array $attrs) { $this->a = $attrs + ['on_delete' => 'NO ACTION', 'on_update' => 'NO ACTION']; }
        public function references($columns): static { $this->a['references'] = (array) $columns; return $this; }
        public function on(string $table): static { $this->a['on'] = $table; return $this; }
        public function restrictOnDelete(): static { $this->a['on_delete'] = 'RESTRICT'; return $this; }
        public function cascadeOnDelete(): static { $this->a['on_delete'] = 'CASCADE'; return $this; }
        public function nullOnDelete(): static { $this->a['on_delete'] = 'SET NULL'; return $this; }
        public function restrictOnUpdate(): static { $this->a['on_update'] = 'RESTRICT'; return $this; }
        public function __call($m, $args) { throw new HarnessError("ForeignKeyDefinition::$m() is not used by Pluto migrations"); }
    }

    final class Blueprint
    {
        public array $columns = [];
        public array $commands = [];
        public array $options = [];

        public function __construct(public string $table) {}

        // Table options (Laravel 9+ method forms).
        public function engine(string $engine): void { $this->options['engine'] = $engine; }
        public function charset(string $charset): void { $this->options['charset'] = $charset; }
        public function collation(string $collation): void { $this->options['collation'] = $collation; }
        public function comment(string $comment): void { $this->options['comment'] = $comment; }

        private function col(string $type, string $name, array $extra = []): ColumnDefinition
        {
            foreach ($this->columns as $c) {
                if ($c->a['name'] === $name) throw new HarnessError("{$this->table}: duplicate column $name");
            }
            $c = new ColumnDefinition(['type' => $type, 'name' => $name] + $extra);
            $this->columns[] = $c;
            return $c;
        }

        // Column types: names and MySQL output follow Illuminate\Database\Schema\Grammars\MySqlGrammar.
        public function id(string $column = 'id'): ColumnDefinition { return $this->col('bigint unsigned', $column, ['auto_increment' => true, 'primary' => true]); }
        public function unsignedBigInteger(string $column, bool $autoIncrement = false): ColumnDefinition { return $this->col('bigint unsigned', $column); }
        public function unsignedInteger(string $column, bool $autoIncrement = false): ColumnDefinition { return $this->col('int unsigned', $column); }
        public function unsignedSmallInteger(string $column, bool $autoIncrement = false): ColumnDefinition { return $this->col('smallint unsigned', $column); }
        public function unsignedTinyInteger(string $column, bool $autoIncrement = false): ColumnDefinition { return $this->col('tinyint unsigned', $column); }
        public function integer(string $column, bool $autoIncrement = false, bool $unsigned = false): ColumnDefinition { return $this->col('int', $column); }
        public function string(string $column, ?int $length = null): ColumnDefinition { return $this->col('varchar(' . ($length ?? 255) . ')', $column); }
        public function char(string $column, ?int $length = null): ColumnDefinition { return $this->col('char(' . ($length ?? 255) . ')', $column); }
        public function decimal(string $column, int $total = 8, int $places = 2): ColumnDefinition { return $this->col("decimal($total,$places)", $column); }
        public function boolean(string $column): ColumnDefinition { return $this->col('tinyint(1)', $column); }
        public function date(string $column): ColumnDefinition { return $this->col('date', $column); }
        public function dateTime(string $column, ?int $precision = 0): ColumnDefinition { return $this->col($precision ? "datetime($precision)" : 'datetime', $column); }
        public function json(string $column): ColumnDefinition { return $this->col('json', $column); }
        public function text(string $column): ColumnDefinition { return $this->col('text', $column); }

        private function cmd(string $kind, $columns, ?string $name): void
        {
            $columns = (array) $columns;
            foreach ($columns as $c) {
                if (!$this->hasColumn($c)) throw new HarnessError("{$this->table}: $kind on unknown column $c");
            }
            if ($kind !== 'primary' && $name === null) throw new HarnessError("{$this->table}: $kind without explicit name");
            $this->commands[] = ['kind' => $kind, 'columns' => $columns, 'name' => $name];
        }
        public function primary($columns, ?string $name = null, ?string $algorithm = null): void { $this->cmd('primary', $columns, $name); }
        public function unique($columns, ?string $name = null, ?string $algorithm = null): void { $this->cmd('unique', $columns, $name); }
        public function index($columns, ?string $name = null, ?string $algorithm = null): void { $this->cmd('index', $columns, $name); }
        public function foreign($columns, ?string $name = null): ForeignKeyDefinition
        {
            $columns = (array) $columns;
            foreach ($columns as $c) {
                if (!$this->hasColumn($c)) throw new HarnessError("{$this->table}: foreign on unknown column $c");
            }
            $f = new ForeignKeyDefinition(['columns' => $columns, 'name' => $name]);
            $this->commands[] = ['kind' => 'foreign', 'def' => $f];
            return $f;
        }
        public function hasColumn(string $c): bool { foreach ($this->columns as $x) { if ($x->a['name'] === $c) return true; } return false; }
        public function __call($m, $args) { throw new HarnessError("Blueprint::$m() is not used by Pluto migrations"); }
    }

    final class Registry
    {
        public static array $tables = [];      // name => model
        public static array $constraintNames = []; // schema-wide FK/CHECK names
        public static array $log = [];
    }
}

namespace Illuminate\Support\Facades {
    use Illuminate\Database\Schema\Blueprint;
    use Illuminate\Database\Schema\HarnessError;
    use Illuminate\Database\Schema\Registry as R;

    final class Schema
    {
        public static function create(string $table, \Closure $callback): void
        {
            if (isset(R::$tables[$table])) throw new HarnessError("create: $table already exists");
            $bp = new Blueprint($table);
            $callback($bp);
            $model = ['options' => $bp->options, 'columns' => [], 'primary' => [], 'unique' => [], 'index' => [], 'foreign' => [], 'check' => []];
            foreach ($bp->columns as $c) {
                $a = $c->a;
                $model['columns'][] = [
                    'name' => $a['name'], 'type' => $a['type'], 'nullable' => (bool) $a['nullable'],
                    'default' => array_key_exists('has_default', $a) ? (is_bool($a['default']) ? ($a['default'] ? '1' : '0') : (string) $a['default']) : null,
                    'generated' => $a['storedAs'], 'auto_increment' => !empty($a['auto_increment']),
                ];
                if ($a['primary']) $model['primary'][] = $a['name'];
                if ($a['storedAs'] !== null && !empty($a['has_default'])) throw new HarnessError("$table.{$a['name']}: default on generated column");
            }
            foreach ($bp->commands as $cmd) {
                if ($cmd['kind'] === 'primary') {
                    if ($model['primary']) throw new HarnessError("$table: two primary keys");
                    $model['primary'] = $cmd['columns'];
                } elseif ($cmd['kind'] === 'unique' || $cmd['kind'] === 'index') {
                    if (isset($model['unique'][$cmd['name']]) || isset($model['index'][$cmd['name']])) throw new HarnessError("$table: duplicate index name {$cmd['name']}");
                    $model[$cmd['kind']][$cmd['name']] = $cmd['columns'];
                } else {
                    $f = $cmd['def']->a;
                    foreach (['name', 'references', 'on'] as $k) {
                        if (empty($f[$k])) throw new HarnessError("$table: foreign key without $k");
                    }
                    if ($f['on'] !== $table && !isset(R::$tables[$f['on']])) {
                        throw new HarnessError("$table.{$f['columns'][0]}: references {$f['on']}, which is not created yet (migration order)");
                    }
                    $target = $f['on'] === $table ? $model : R::$tables[$f['on']];
                    $refCol = $f['references'][0];
                    $isKey = $target['primary'] === [$refCol] || in_array([$refCol], array_values($target['unique']), true);
                    if (!$isKey) throw new HarnessError("$table: FK target {$f['on']}.$refCol is not a key");
                    $childType = null; $parentType = null;
                    foreach ($model['columns'] as $c) { if ($c['name'] === $f['columns'][0]) $childType = $c; }
                    foreach ($target['columns'] as $c) { if ($c['name'] === $refCol) $parentType = $c; }
                    if ($childType['type'] !== $parentType['type']) throw new HarnessError("$table.{$f['columns'][0]}: type {$childType['type']} ≠ {$f['on']}.$refCol {$parentType['type']}");
                    if ($f['on_delete'] === 'SET NULL' && !$childType['nullable']) throw new HarnessError("$table.{$f['columns'][0]}: SET NULL on NOT NULL");
                    if (isset(R::$constraintNames[$f['name']])) throw new HarnessError("duplicate constraint name {$f['name']}");
                    if (strlen($f['name']) > 64) throw new HarnessError("identifier too long {$f['name']}");
                    R::$constraintNames[$f['name']] = $table;
                    $model['foreign'][$f['name']] = ['column' => $f['columns'][0], 'references_table' => $f['on'], 'references_column' => $refCol,
                        'on_delete' => $f['on_delete'], 'on_update' => $f['on_update']];
                }
            }
            foreach (array_merge(array_keys($model['unique']), array_keys($model['index'])) as $n) {
                if (strlen($n) > 64) throw new HarnessError("identifier too long $n");
            }
            if (!$model['primary']) throw new HarnessError("$table: no primary key");
            R::$tables[$table] = $model;
            R::$log[] = "create $table";
        }

        public static function dropIfExists(string $table): void
        {
            foreach (R::$tables as $other => $m) {
                if ($other === $table) continue;
                foreach ($m['foreign'] as $fk) {
                    if ($fk['references_table'] === $table) throw new HarnessError("drop $table: still referenced by $other.{$fk['column']} (down order)");
                }
            }
            unset(R::$tables[$table]);
            R::$log[] = "drop $table";
        }

        public static function __callStatic($m, $a) { throw new HarnessError("Schema::$m() is not used by Pluto migrations"); }
    }

    final class DB
    {
        public static function connection(?string $name = null): object
        {
            return new class { public function getDriverName(): string { return 'mysql'; } };
        }

        public static function statement(string $sql): bool
        {
            if (!preg_match('/^\s*ALTER TABLE `([a-z0-9_]+)`\s+(.*)$/s', $sql, $m)) throw new HarnessError("unexpected statement: " . substr($sql, 0, 80));
            $table = $m[1];
            if (!isset(R::$tables[$table])) throw new HarnessError("ALTER $table before create");
            $parts = preg_split('/,\s*\n\s*(?=ADD CONSTRAINT)/', trim($m[2]));
            foreach ($parts as $p) {
                if (!preg_match('/^ADD CONSTRAINT `([a-z0-9_]+)` CHECK \((.*)\)$/s', trim($p), $c)) throw new HarnessError("$table: bad CHECK clause " . substr($p, 0, 80));
                [$all, $name, $expr] = $c;
                if (isset(R::$constraintNames[$name])) throw new HarnessError("duplicate constraint name $name");
                if (strlen($name) > 64) throw new HarnessError("identifier too long $name");
                if (substr_count($expr, '(') !== substr_count($expr, ')')) throw new HarnessError("$name: unbalanced parentheses");
                if (substr_count($expr, "'") % 2) throw new HarnessError("$name: unbalanced quotes");
                $cols = array_column(R::$tables[$table]['columns'], 'name');
                preg_match_all('/`([a-z0-9_]+)`/', $expr, $refs);
                foreach ($refs[1] as $r) {
                    if (!in_array($r, $cols, true)) throw new HarnessError("$name: unknown column $r");
                }
                R::$constraintNames[$name] = $table;
                R::$tables[$table]['check'][$name] = $expr;
            }
            return true;
        }

        public static function __callStatic($m, $a) { throw new HarnessError("DB::$m() is not used by Pluto migrations"); }
    }
}

namespace {
    use Illuminate\Database\Schema\Registry as R;

    [$dir, $out] = [$argv[1] ?? null, $argv[2] ?? null];
    if (!$dir || !$out) { fwrite(STDERR, "usage: php harness.php <migrations-dir> <out.json>\n"); exit(2); }
    // Laravel's own cache and jobs migrations (0001_01_01_*) stay as the framework ships them: not part of the contract.
    $files = array_filter(glob(rtrim($dir, '/') . '/*.php'), fn ($f) => !str_starts_with(basename($f), '0001_01_01_'));
    sort($files, SORT_STRING);
    $migrations = [];
    try {
        foreach ($files as $f) {
            $m = require $f;
            if (!$m instanceof \Illuminate\Database\Migrations\Migration) throw new \RuntimeException(basename($f) . ' does not return a Migration');
            $migrations[basename($f)] = $m;
            $m->up();
        }
        $snapshot = R::$tables;
        $count = count($snapshot);
        foreach (array_reverse($migrations, true) as $name => $m) {
            $m->down();
        }
        if (R::$tables) throw new \RuntimeException('tables left after rollback: ' . implode(', ', array_keys(R::$tables)));
    } catch (\Throwable $e) {
        fwrite(STDERR, 'HARNESS FAILURE: ' . $e->getMessage() . "\n");
        exit(1);
    }
    file_put_contents($out, json_encode(['order' => array_keys($migrations), 'tables' => $snapshot], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo "harness: migrated=$count rolled_back=$count files=" . count($files) . "\n";
}
