<?php

namespace Tests\Feature\Access;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Company isolation sweep before selling Pluto to many companies (PEP-486; BR-037). A rival company gets one of most
 * kinds of record, each named with the marker ZZRIVAL. Then, signed in to Meghna (Owner, every permission), every
 * company-scoped operation of the contract is called:
 *  - every operation with an {id}: the rival's id of the operation's first table → never a 2xx answer, and the rival's
 *    rows in that table are unchanged after a write;
 *  - every list/report without path parameters: the answer never contains the marker.
 * Record kinds the API seed does not make get one minimal rival row from database/schema-contract.json (rivalRow), so the
 * sweep covers 155 of the 156 {id} operations (13 Nov 2026); a kind a plain row cannot satisfy is skipped.
 */
class CompanyIsolationSweepTest extends TestCase
{
    use BuildsMasterData;

    private const MARK = 'ZZRIVAL';

    private const VERBS = ['get', 'post', 'put', 'patch', 'delete'];

    private string $token;

    private int $rival;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pluto.rate_limits.api_per_user' => 1_000_000]); // hundreds of calls; a 429 would hide a real answer
        $this->provisionedCompany(); // Meghna Traders Ltd., owner@meghna.example: the attacker's own company
        $this->token = $this->login('owner@meghna.example');
        $this->rival = $this->makeCompany(self::MARK.' Traders Ltd.', $this->makeUser('owner@rival.example'))->id;
        $this->seedRival($this->login('owner@rival.example'));
    }

    /** One of most kinds of record in the rival company, through the API as its own Owner. */
    private function seedRival(string $t): void
    {
        $c = $this->rival;
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $call = function (string $method, string $uri, array $body = []) use ($t, $c) {
            $r = $this->api($method, '/api/v1'.$uri, $t, $c, $body, $method === 'GET' ? [] : ['Idempotency-Key' => (string) Str::uuid()]);
            $this->assertLessThan(300, $r->status(), "seeding {$method} {$uri}: ".$r->getContent());

            return $r->json('data');
        };
        $m = self::MARK;
        $branch = $call('GET', '/branches')[0]['id'];
        $wh = $call('POST', '/warehouses', ['code' => 'WH-ZZ', 'name' => "{$m} depot", 'branch_id' => $branch])['id'];
        $wh2 = $call('POST', '/warehouses', ['code' => 'WH-ZZ2', 'name' => "{$m} second depot", 'branch_id' => $branch])['id'];
        $unit = $call('GET', '/units', ['per_page' => 100])[0]['id'];
        $income = $call('GET', '/ledgers', ['purpose' => 'income_for_product', 'per_page' => 100])[0]['id'];
        $bank = $call('POST', '/money-accounts', ['account_type' => 'bank', 'bank_name' => "{$m} Bank", 'account_name' => "{$m} Traders", 'account_number' => '9900112233',
            'opening_balance' => '5000000.00'])['id'];
        $supplier = $call('POST', '/parties', ['party_type' => 'supplier', 'name' => "{$m} Supplier", 'mobile' => '01711999001'])['id'];
        $customer = $call('POST', '/parties', ['party_type' => 'customer', 'name' => "{$m} Customer", 'mobile' => '01711999002'])['id'];
        $product = $call('POST', '/products', ['item_type' => 'product', 'name' => "{$m} oil", 'unit_id' => $unit, 'selling_price' => '950.00', 'income_ledger_id' => $income])['id'];

        $bill = $call('POST', '/purchase-bills', ['party_id' => $supplier, 'supplier_bill_no' => "{$m}-1", 'bill_date' => $today, 'warehouse_id' => $wh,
            'lines' => [['product_id' => $product, 'quantity' => '100', 'unit_cost' => '800.00']]]);
        $call('POST', "/purchase-bills/{$bill['id']}/post", []);
        $call('POST', '/purchase-bills', ['party_id' => $supplier, 'supplier_bill_no' => "{$m}-2", 'bill_date' => $today, 'warehouse_id' => $wh,
            'lines' => [['product_id' => $product, 'quantity' => '5', 'unit_cost' => '800.00']]]); // a draft too
        $inv = $call('POST', '/sales-invoices', ['party_id' => $customer, 'invoice_date' => $today, 'warehouse_id' => $wh, 'customer_po_ref' => "{$m}-PO",
            'lines' => [['product_id' => $product, 'quantity' => '10']]]);
        $call('POST', "/sales-invoices/{$inv['id']}/post", []);
        $call('POST', '/sales-invoices', ['party_id' => $customer, 'invoice_date' => $today, 'warehouse_id' => $wh, 'lines' => [['product_id' => $product, 'quantity' => '1']]]);
        $call('POST', '/customer-receipts', ['party_id' => $customer, 'date' => $today, 'amount' => '1000.00', 'money_account_id' => $bank, 'method' => 'bank_transfer', 'auto_allocate' => true]);
        $call('POST', '/supplier-payments', ['party_id' => $supplier, 'date' => $today, 'amount' => '1000.00', 'money_account_id' => $bank, 'method' => 'bank_transfer', 'auto_allocate' => true]);
        $cat = $call('POST', '/ie-categories', ['kind' => 'expense', 'name' => "{$m} rent", 'create_ledger' => true])['id'];
        $call('POST', '/ie-entries', ['kind' => 'expense', 'amount' => '500.00', 'date' => $today, 'category_id' => $cat, 'money_account_id' => $bank, 'method' => 'bank_transfer']);
        $call('POST', '/purchase-orders', ['party_id' => $supplier, 'order_date' => $today, 'warehouse_id' => $wh, 'lines' => [['product_id' => $product, 'quantity' => '50', 'unit_cost' => '790.00']]]);
        $call('POST', '/quotations', ['party_id' => $customer, 'quotation_date' => $today, 'valid_until' => CarbonImmutable::parse($today)->addDays(15)->toDateString(), 'warehouse_id' => $wh,
            'lines' => [['product_id' => $product, 'quantity' => '3']]]);
        $call('POST', '/stock-adjustments', ['adjustment_date' => $today, 'warehouse_id' => $wh, 'reason' => 'damaged', 'notes' => "{$m} adjustment",
            'lines' => [['product_id' => $product, 'counted_qty' => '88']]]);
        $call('POST', '/stock-transfers', ['dispatch_date' => $today, 'from_warehouse_id' => $wh, 'to_warehouse_id' => $wh2, 'notes' => "{$m} transfer",
            'lines' => [['product_id' => $product, 'quantity' => '2']]]);
        $lc = $call('POST', '/letters-of-credit', ['party_id' => $supplier, 'goods_description' => "{$m} palm oil", 'tenor' => 'sight', 'incoterm' => 'CFR Chattogram',
            'currency_code' => 'USD', 'amount' => '1000.00', 'planning_rate' => '121.50', 'issuing_bank_account_id' => $bank,
            'expiry_date' => CarbonImmutable::parse($today)->addDays(60)->toDateString()]);
        $call('POST', "/letters-of-credit/{$lc['id']}/documents", ['name' => "{$m} bill of lading", 'provided_by' => 'Beneficiary']);
    }

    /** @return list<array{0: string, 1: string, 2: array}> [METHOD, path, operation] of company-scoped operations */
    private static function operations(): array
    {
        $ops = [];
        foreach (Yaml::parseFile(base_path('docs/openapi.yaml'))['paths'] as $path => $item) {
            foreach (self::VERBS as $verb) {
                $op = $item[$verb] ?? null;
                if ($op && ! in_array($op['x-permission'] ?? 'public', ['public', 'auth', 'auth.self'], true)) {
                    $ops[] = [strtoupper($verb), $path, $op];
                }
            }
        }

        return $ops;
    }

    private static ?array $schema = null;

    /**
     * A rival row of `$table` for operations the API seed does not reach (returns, notes, loans, …): one minimal row built
     * from database/schema-contract.json — required foreign keys point at the rival's own rows (made the same way),
     * values allowed by the table's CHECK constraints, everything else a plain default. The row only has to exist and
     * belong to the rival: the sweep checks that Meghna cannot reach it.
     */
    private function rivalRow(string $table, int $depth = 0): ?int
    {
        $id = DB::table($table)->where('company_id', $this->rival)->orderBy('id')->value('id');
        if ($id || $depth > 6) {
            return $id ? (int) $id : null;
        }
        $t = (self::$schema ??= json_decode(file_get_contents(base_path('database/schema-contract.json')), true))['tables'][$table] ?? null;
        if (! $t) {
            return null;
        }
        $fks = array_column($t['foreign'] ?? [], 'references_table', 'column');
        $checks = implode(' ', (array) ($t['check'] ?? []));
        $row = ['company_id' => $this->rival];
        foreach ($t['columns'] as $c) {
            $n = $c['name'];
            if ($n === 'id' || $n === 'company_id' || $c['generated'] || $c['nullable'] || $c['default'] !== null) {
                continue;
            }
            if (isset($fks[$n])) {
                $ref = $fks[$n];
                $row[$n] = match ($ref) {
                    'users' => (int) DB::table('company_users')->where('company_id', $this->rival)->value('user_id'),
                    'companies' => $this->rival,
                    default => DB::getSchemaBuilder()->hasColumn($ref, 'company_id') ? $this->rivalRow($ref, $depth + 1) : (int) DB::table($ref)->orderBy('id')->value('id'),
                };
                if (! $row[$n]) {
                    return null;
                }

                continue;
            }
            $type = strtolower($c['type']);
            $row[$n] = match (true) {
                preg_match("/`?{$n}`? IN \\('([^']+)'/i", $checks, $m) === 1 => $m[1],
                str_starts_with($type, 'decimal'), str_contains($type, 'int') => 1,
                $type === 'date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
                str_starts_with($type, 'timestamp'), str_starts_with($type, 'datetime') => now(),
                $type === 'json' => '{}',
                str_starts_with($type, 'char(') => str_pad(substr(md5($table.$n), 0, (int) filter_var($type, FILTER_SANITIZE_NUMBER_INT)), (int) filter_var($type, FILTER_SANITIZE_NUMBER_INT), '0'),
                default => substr(self::MARK.'-'.$table, 0, max(4, (int) filter_var($type, FILTER_SANITIZE_NUMBER_INT) ?: 30)),
            };
        }
        try {
            return (int) DB::table($table)->insertGetId($row);
        } catch (\Throwable) {
            return null; // a table whose rules a plain row cannot meet stays skipped (reported)
        }
    }

    /** Paths whose resource name is not its table (the {id} is a row of the table on the right). */
    private const ALIASES = ['letters-of-credit' => 'letters_of_credit', 'customers' => 'parties', 'suppliers' => 'parties', 'vouchers' => 'journal_entries',
        'customer-receipts' => 'journal_entries', 'supplier-payments' => 'journal_entries', 'ie-entries' => 'journal_entries', 'money-transactions' => 'journal_entries',
        'users' => 'company_users', 'lc-loans' => 'lc_loans', 'lc-payments' => 'lc_payments', 'lc-charges' => 'lc_charges', 'lc-documents' => 'lc_documents',
        'lc-shipments' => 'lc_shipments', 'lc-amendments' => 'lc_amendments', 'lc-loan-repayments' => 'lc_loan_repayments', 'lc-document-templates' => 'lc_document_templates'];

    /** The table the {id} of a path belongs to: the resource right before it (e.g. /ledgers/{id}/entries → ledgers). */
    private static function idTable(string $path): ?string
    {
        if (! preg_match('#/([a-z-]+)/\{id\}#', $path, $m)) {
            return null;
        }
        $table = self::ALIASES[$m[1]] ?? str_replace('-', '_', $m[1]);

        return DB::getSchemaBuilder()->hasTable($table) ? $table : null;
    }

    private function snapshot(string $table): string
    {
        return md5(json_encode(DB::table($table)->where('company_id', $this->rival)->orderBy('id')->get()));
    }

    public function test_pep_486_no_operation_reaches_another_companys_records(): void
    {
        $leaks = [];
        $covered = $skipped = 0;
        foreach (self::operations() as [$method, $path, $op]) {
            if (! str_contains($path, '{id}')) {
                continue;
            }
            $table = self::idTable($path) ?? ($op['x-tables'][0] ?? null);
            $hasCompany = $table && DB::getSchemaBuilder()->hasColumn($table, 'company_id');
            $id = $hasCompany ? $this->rivalRow($table) : null;
            if (! $id || preg_match('/\{(?!id\})[a-z_]+\}/', $path)) {
                $skipped++;

                continue;
            }
            $before = $method === 'GET' ? null : $this->snapshot($table);
            $uri = preg_replace('/^\/api\/v1/', '', str_replace('{id}', (string) $id, $path));
            $h = ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => 'W/"1"'];
            $r = $this->api($method, '/api/v1'.$uri, $this->token, $this->books->id, $method === 'GET' ? [] : ['reason' => self::MARK.' probe'], $method === 'GET' ? [] : $h);
            $this->useBooks();
            if ($r->status() === 429) {
                $leaks[] = "{$method} {$path} was rate limited: the sweep did not test it";
            } elseif ($r->status() < 300) {
                $leaks[] = "{$method} {$path} with the rival's {$table} #{$id} answered {$r->status()}";
            } elseif (str_contains((string) $r->getContent(), self::MARK)) {
                $leaks[] = "{$method} {$path} refused with {$r->status()} but its answer names the rival's data";
            }
            if ($before !== null && $this->snapshot($table) !== $before) {
                $leaks[] = "{$method} {$path} changed the rival's {$table}";
            }
            $covered++;
        }
        $this->assertSame([], $leaks);
        $this->assertGreaterThan(80, $covered, "only {$covered} id operations swept ({$skipped} skipped: no rival row)");
    }

    public function test_pep_486_no_list_or_report_shows_another_companys_data(): void
    {
        $leaks = [];
        $n = 0;
        foreach (self::operations() as [$method, $path, $op]) {
            if ($method !== 'GET' || str_contains($path, '{')) {
                continue;
            }
            $r = $this->api('GET', $path, $this->token, $this->books->id);
            $this->useBooks();
            $n++;
            if ($r->status() === 429) {
                $leaks[] = "GET {$path} was rate limited: the sweep did not test it";
            }
            if (str_contains((string) $r->getContent(), self::MARK)) {
                $leaks[] = "GET {$path} ({$r->status()}) shows the rival's data";
            }
        }
        $this->assertSame([], $leaks);
        $this->assertGreaterThan(60, $n);
        // and the probe works: the rival itself sees its own marker
        $own = $this->api('GET', '/api/v1/parties?type=customer', $this->login('owner@rival.example'), $this->rival)->assertOk();
        $this->assertStringContainsString(self::MARK, (string) $own->getContent());
    }
}
