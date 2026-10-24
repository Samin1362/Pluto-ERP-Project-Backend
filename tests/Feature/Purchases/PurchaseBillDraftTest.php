<?php

namespace Tests\Feature\Purchases;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Draft purchase bills: POST/GET/PUT/DELETE /purchase-bills (F3; BR-006, BR-018, BR-025, BR-049, BR-050; TC-PUR-001, TC-ACC-023; P4.2-BE-02). */
class PurchaseBillDraftTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $wh;

    private Product $oil;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->wh = $this->warehouse('WH-NGJ', 'Narayanganj depot');
        $this->oil = $this->product('Soybean oil 5L jar');
        $this->prefix = 'BILL-'.FiscalYear::whereDate('start_date', '<=', $this->today())->whereDate('end_date', '>=', $this->today())->value('start_date')->year.'-';
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function body(array $over = []): array
    {
        return $over + ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'CST-0918', 'bill_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '740', 'unit_cost' => '846.00']]];
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        if ($method === 'POST') {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    public function test_create_reserves_the_number_and_stores_the_calculated_bill(): void
    {
        $net15 = DB::table('payment_terms')->where('company_id', $this->books->id)->where('name', 'Net 15')->value('id');
        $r = $this->req('POST', '/api/v1/purchase-bills', $this->body(['payment_term_id' => $net15, 'notes' => 'Received in good condition',
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '740', 'unit_cost' => '846.00'], ['product_id' => $this->oil->id, 'quantity' => '10', 'unit_cost' => '850', 'discount_pct' => '2']]]))
            ->assertCreated()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.number', "{$this->prefix}0001")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.payment_status', 'unpaid')
            ->assertJsonPath('data.overdue', false)->assertJsonPath('data.party.code', 'SUP-0001')->assertJsonPath('data.supplier_bill_no', 'CST-0918')
            ->assertJsonPath('data.due_date', CarbonImmutable::parse($this->today())->addDays(15)->toDateString())
            ->assertJsonPath('data.currency_code', 'BDT')->assertJsonPath('data.exchange_rate', '1.000000')->assertJsonPath('data.total_foreign', null)
            ->assertJsonPath('data.subtotal', '634540.00')->assertJsonPath('data.discount_total', '170.00')->assertJsonPath('data.total', '634370.00')
            ->assertJsonPath('data.balance_due', '634370.00')->assertJsonPath('data.amount_settled', '0.00')->assertJsonPath('data.journal', [])
            ->assertJsonPath('data.lines.1.discount_amount', '170.00')->assertJsonPath('data.lines.1.net_amount', '8330.00')->assertJsonPath('data.lines.0.unit', 'jar')
            ->assertJsonPath('data.created_by.name', 'Owner')->assertJsonPath('data.version', 1);
        $bill = PurchaseBill::with('lines')->findOrFail($r->json('data.id'));
        $this->assertSame([$this->wh->inventory_ledger_id, $this->wh->inventory_ledger_id], $bill->lines->pluck('debit_ledger_id')->all(), 'stock lines post to the warehouse inventory');
        $this->assertSame('Bengal Edible Oils', $bill->party_snapshot['name']);
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'purchase_bills')->where('auditable_id', $bill->id)->exists());
        $this->assertSame(0, DB::table('stock_movements')->count() + DB::table('journal_entries')->count(), 'a draft changes no stock and no books');

        $this->req('GET', "/api/v1/purchase-bills/{$bill->id}")->assertOk()->assertHeader('ETag', 'W/"1"')->assertJsonPath('data.number', "{$this->prefix}0001")
            ->assertJsonCount(2, 'data.lines')->assertJsonPath('data.amount_in_words', 'Taka six lakh thirty-four thousand three hundred seventy only');
    }

    public function test_tc_pur_001_supplier_bill_number_required_and_unique_per_supplier(): void
    {
        $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $this->body(['supplier_bill_no' => ' '])), 422, 'validation_failed')
            ->assertJsonPath('errors.supplier_bill_no.0', "Enter the supplier's bill number");
        $first = $this->req('POST', '/api/v1/purchase-bills', $this->body())->assertCreated()->json('data.number');
        // The contract and the error catalogue make this a 409 (TC-PUR-001's text says 422).
        $dup = $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $this->body()), 409, 'duplicate_supplier_bill');
        $dup->assertJsonPath('detail', "This supplier's bill CST-0918 is already entered as {$first}.")->assertJsonPath('existing_number', $first)
            ->assertJsonPath('errors.supplier_bill_no.0', "Already entered as {$first}");
        $other = $this->supplier('Meghna Packaging', '01811000222');
        $this->req('POST', '/api/v1/purchase-bills', $this->body(['party_id' => $other->id]))->assertCreated(); // same number, other supplier
    }

    public function test_replace_with_if_match_lines_replaced_drafts_only(): void
    {
        $b = $this->req('POST', '/api/v1/purchase-bills', $this->body(['lines' => [['product_id' => $this->oil->id, 'quantity' => '1', 'unit_cost' => '1'],
            ['product_id' => $this->oil->id, 'quantity' => '2', 'unit_cost' => '2']]]))->json('data');
        $this->assertProblem($this->req('PUT', "/api/v1/purchase-bills/{$b['id']}", $this->body()), 428, 'if_match_required');
        $r = $this->req('PUT', "/api/v1/purchase-bills/{$b['id']}", $this->body(['supplier_bill_no' => 'CST-0918A']), ['If-Match' => 'W/"1"'])->assertOk();
        $r->assertJsonPath('data.version', 2)->assertJsonPath('data.number', $b['number'], 'the number stays')->assertJsonCount(1, 'data.lines')
            ->assertJsonPath('data.total', '626040.00')->assertHeader('ETag', 'W/"2"');
        $this->assertSame(1, DB::table('purchase_bill_lines')->where('purchase_bill_id', $b['id'])->count());
        $this->assertTrue(AuditLog::where('action', 'updated')->where('auditable_type', 'purchase_bills')->where('auditable_id', $b['id'])->exists());
        $this->assertProblem($this->req('PUT', "/api/v1/purchase-bills/{$b['id']}", $this->body(), ['If-Match' => 'W/"1"']), 409, 'version_conflict')->assertJsonPath('current_version', 2);

        // Its own supplier bill number is not a duplicate; another bill's is.
        $this->req('PUT', "/api/v1/purchase-bills/{$b['id']}", $this->body(['supplier_bill_no' => 'CST-0918A']), ['If-Match' => 'W/"2"'])->assertOk();
        $second = $this->req('POST', '/api/v1/purchase-bills', $this->body(['supplier_bill_no' => 'CST-0919']))->json('data');
        $this->assertProblem($this->req('PUT', "/api/v1/purchase-bills/{$second['id']}", $this->body(['supplier_bill_no' => 'CST-0918A']), ['If-Match' => 'W/"1"']), 409, 'duplicate_supplier_bill');

        DB::table('purchase_bills')->where('id', $b['id'])->update(['status' => 'posted']);
        $this->assertProblem($this->req('PUT', "/api/v1/purchase-bills/{$b['id']}", $this->body(), ['If-Match' => 'W/"3"']), 409, 'not_draft');
        $this->assertProblem($this->req('DELETE', "/api/v1/purchase-bills/{$b['id']}"), 409, 'not_draft');
    }

    public function test_tc_acc_023_numbers_are_consecutive_and_a_deleted_drafts_number_is_voided_never_reissued(): void
    {
        $numbers = [];
        for ($i = 1; $i <= 50; $i++) {
            $numbers[] = $this->req('POST', '/api/v1/purchase-bills', $this->body(['supplier_bill_no' => "SB-{$i}"]))->assertCreated()->json('data.number');
        }
        $this->assertSame(array_map(fn ($n) => $this->prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT), range(1, 50)), $numbers);

        $last = PurchaseBill::where('number', "{$this->prefix}0050")->firstOrFail();
        $this->req('DELETE', "/api/v1/purchase-bills/{$last->id}")->assertNoContent();
        $this->assertSame(0, DB::table('purchase_bill_lines')->where('purchase_bill_id', $last->id)->count());
        $void = AuditLog::where('action', 'voided_number')->where('auditable_type', 'purchase_bills')->where('auditable_id', $last->id)->firstOrFail();
        $this->assertSame(['number' => "{$this->prefix}0050"], $void->old_values);
        $this->assertProblem($this->req('GET', "/api/v1/purchase-bills/{$last->id}"), 404, 'not_found');
        $this->req('POST', '/api/v1/purchase-bills', $this->body(['supplier_bill_no' => 'SB-51']))->assertJsonPath('data.number', "{$this->prefix}0051", 'never reissued');
    }

    public function test_idempotent_create_locked_period_permissions_and_isolation(): void
    {
        $key = (string) Str::uuid();
        $a = $this->req('POST', '/api/v1/purchase-bills', $this->body(), ['Idempotency-Key' => $key])->assertCreated();
        $this->req('POST', '/api/v1/purchase-bills', $this->body(), ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $a->json('data.id'));
        $this->assertSame(1, PurchaseBill::count());

        AccountingPeriod::whereDate('start_date', '<=', $this->today())->whereDate('end_date', '>=', $this->today())->update(['status' => 'locked']);
        $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $this->body(['supplier_bill_no' => 'X-1'])), 422, 'period_locked');
        AccountingPeriod::query()->where('status', 'locked')->update(['status' => 'open']);

        $this->userWithRole('store');
        $store = $this->login('store@meghna.example');
        $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $this->body(['supplier_bill_no' => 'X-2']), token: $store), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', '/api/v1/purchase-bills/'.$a->json('data.id'), token: $viewer)->assertOk();
        $this->assertProblem($this->req('DELETE', '/api/v1/purchase-bills/'.$a->json('data.id'), token: $viewer), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', '/api/v1/purchase-bills/'.$a->json('data.id'), $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('DELETE', '/api/v1/purchase-bills/'.$a->json('data.id'), $ot, $other->id), 404, 'not_found');
    }
}
