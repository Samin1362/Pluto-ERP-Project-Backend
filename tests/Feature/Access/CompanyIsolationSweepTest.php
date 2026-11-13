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
 * Operations whose table has no rival row are listed as skipped; the sweep must still cover most of the contract.
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
            $id = $hasCompany ? DB::table($table)->where('company_id', $this->rival)->orderBy('id')->value('id') : null;
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
