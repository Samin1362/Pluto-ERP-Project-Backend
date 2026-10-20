<?php

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs the real migrations on MySQL 8.0 and compares information_schema with
 * database/schema-contract.json (generated from docs/source/schema.py).
 *
 * Run in CI against a disposable MySQL 8.0 database (see docs/14 §8):
 *   DB_CONNECTION=mysql DB_DATABASE=ledgerflow_test php artisan test --filter=SchemaContractTest
 */
class SchemaContractTest extends TestCase
{
    use RefreshDatabase;

    /** Framework tables from Laravel's own default migrations, not part of the LedgerFlow contract. */
    private const FRAMEWORK_TABLES = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    private array $contract;
    private string $schema;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('The schema contract is defined for MySQL 8.0.');
        }
        $this->contract = json_decode(file_get_contents(base_path('database/schema-contract.json')), true, flags: JSON_THROW_ON_ERROR)['tables'];
        $this->schema = DB::connection()->getDatabaseName();
    }

    public function test_server_is_mysql_8_0_or_later(): void
    {
        $version = DB::selectOne('SELECT VERSION() AS v')->v;
        $this->assertTrue(version_compare($version, '8.0.16', '>='), "CHECK constraints need MySQL 8.0.16+, got $version");
    }

    public function test_tables_match_contract(): void
    {
        $actual = array_column(DB::select('SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$this->schema, 'BASE TABLE']), 't');
        $actual = array_values(array_diff($actual, self::FRAMEWORK_TABLES));
        sort($actual);
        $expected = array_keys($this->contract);
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    public function test_columns_match_contract(): void
    {
        foreach ($this->contract as $table => $spec) {
            $rows = DB::select('SELECT COLUMN_NAME n, COLUMN_TYPE t, IS_NULLABLE nul, COLUMN_DEFAULT d, EXTRA x
                                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$this->schema, $table]);
            $this->assertSame(array_column($spec['columns'], 'name'), array_column($rows, 'n'), "$table column order");
            foreach ($spec['columns'] as $i => $col) {
                $row = $rows[$i];
                $this->assertSame($col['type'], $row->t, "$table.{$col['name']} type");
                $this->assertSame($col['nullable'], $row->nul === 'YES', "$table.{$col['name']} nullability");
                if ($col['generated'] !== null) {
                    $this->assertStringContainsString('STORED GENERATED', $row->x, "$table.{$col['name']} generated");
                    continue;
                }
                if ($col['auto_increment']) {
                    $this->assertStringContainsString('auto_increment', $row->x, "$table.{$col['name']} auto_increment");
                }
                if ($col['default'] === null) {
                    $this->assertNull($row->d, "$table.{$col['name']} has no default");
                } elseif (is_numeric($col['default'])) {
                    $this->assertEqualsWithDelta((float) $col['default'], (float) $row->d, 0.0000001, "$table.{$col['name']} default");
                } else {
                    $this->assertSame($col['default'], $row->d, "$table.{$col['name']} default");
                }
            }
        }
    }

    public function test_keys_match_contract(): void
    {
        foreach ($this->contract as $table => $spec) {
            $rows = DB::select('SELECT INDEX_NAME i, NON_UNIQUE nu, COLUMN_NAME c FROM information_schema.STATISTICS
                                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$this->schema, $table]);
            $actual = [];
            foreach ($rows as $r) {
                $actual[$r->i]['unique'] = (int) $r->nu === 0;
                $actual[$r->i]['columns'][] = $r->c;
            }
            $this->assertSame($spec['primary'], $actual['PRIMARY']['columns'] ?? null, "$table primary key");
            foreach (['unique' => true, 'index' => false] as $kind => $unique) {
                foreach ($spec[$kind] as $name => $cols) {
                    $this->assertArrayHasKey($name, $actual, "$table missing $kind $name");
                    $this->assertSame($cols, $actual[$name]['columns'], "$table.$name columns");
                    $this->assertSame($unique, $actual[$name]['unique'], "$table.$name uniqueness");
                }
            }
            $expectedNames = array_merge(['PRIMARY'], array_keys($spec['unique']), array_keys($spec['index']));
            sort($expectedNames);
            $actualNames = array_keys($actual);
            sort($actualNames);
            $this->assertSame($expectedNames, $actualNames, "$table has no undeclared or implicit indexes");
        }
    }

    public function test_foreign_keys_match_contract(): void
    {
        $rows = DB::select('SELECT k.TABLE_NAME t, k.CONSTRAINT_NAME n, k.COLUMN_NAME c, k.REFERENCED_TABLE_NAME rt, k.REFERENCED_COLUMN_NAME rc,
                                   r.DELETE_RULE d, r.UPDATE_RULE u
                            FROM information_schema.KEY_COLUMN_USAGE k
                            JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                              ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
                            WHERE k.TABLE_SCHEMA = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL', [$this->schema]);
        $actual = [];
        foreach ($rows as $r) {
            $actual[$r->t][$r->n] = ['column' => $r->c, 'references_table' => $r->rt, 'references_column' => $r->rc,
                'on_delete' => $r->d, 'on_update' => $r->u];
        }
        foreach ($this->contract as $table => $spec) {
            $exp = $spec['foreign'];
            $got = $actual[$table] ?? [];
            ksort($exp);
            ksort($got);
            $this->assertSame($exp, $got, "$table foreign keys");
        }
    }

    public function test_check_constraints_match_contract(): void
    {
        $rows = DB::select("SELECT TABLE_NAME t, CONSTRAINT_NAME n FROM information_schema.TABLE_CONSTRAINTS
                            WHERE TABLE_SCHEMA = ? AND CONSTRAINT_TYPE = 'CHECK' AND ENFORCED = 'YES'", [$this->schema]);
        $actual = [];
        foreach ($rows as $r) {
            $actual[$r->t][] = $r->n;
        }
        foreach ($this->contract as $table => $spec) {
            $exp = array_keys($spec['check']);
            $got = $actual[$table] ?? [];
            sort($exp);
            sort($got);
            $this->assertSame($exp, $got, "$table CHECK constraints");
        }
    }

    public function test_constraints_reject_invalid_rows(): void
    {
        $now = now()->utc();
        $userId = DB::table('users')->insertGetId(['name' => 'Contract', 'email' => 'contract@example.test', 'password_hash' => 'x', 'created_at' => $now]);
        DB::table('currencies')->insertOrIgnore(['code' => 'BDT', 'name' => 'Taka', 'symbol' => '৳']);
        $companyId = DB::table('companies')->insertGetId(['legal_name' => 'Contract Ltd', 'business_type' => 'private_limited', 'base_currency_code' => 'BDT', 'created_at' => $now]);

        // Enum CHECK
        $this->assertRejected(fn () => DB::table('companies')->where('id', $companyId)->update(['status' => 'not_a_status']));

        // Never-reset sequence must not carry a financial year, and vice versa (R-15)
        $this->assertRejected(fn () => DB::table('number_sequences')->insert(['company_id' => $companyId, 'document_type' => 'customer', 'prefix' => 'CUS-', 'reset_policy' => 'fiscal_year']));
        DB::table('number_sequences')->insert(['company_id' => $companyId, 'document_type' => 'customer', 'prefix' => 'CUS-', 'reset_policy' => 'never']);
        $this->assertRejected(fn () => DB::table('number_sequences')->insert(['company_id' => $companyId, 'document_type' => 'customer', 'prefix' => 'CUS-', 'reset_policy' => 'never']));

        // Custom roles need explicit approval limits (R-10)
        $this->assertRejected(fn () => DB::table('roles')->insert(['company_id' => $companyId, 'name' => 'Custom', 'slug' => 'custom', 'is_system' => 0]));

        // Actor columns are RESTRICT: a user who created rows cannot be deleted (deactivate instead)
        DB::table('branches')->insert(['company_id' => $companyId, 'code' => 'HO', 'name' => 'Head office', 'created_by' => $userId, 'created_at' => $now]);
        $this->assertRejected(fn () => DB::table('users')->where('id', $userId)->delete());
    }

    private function assertRejected(\Closure $write): void
    {
        try {
            DB::transaction(fn () => $write());
        } catch (QueryException $e) {
            $this->assertContains((int) ($e->errorInfo[1] ?? 0), [3819, 1062, 1451, 1452, 1048, 1364], $e->getMessage());
            return;
        }
        $this->fail('The database accepted a row that violates a constraint.');
    }
}
