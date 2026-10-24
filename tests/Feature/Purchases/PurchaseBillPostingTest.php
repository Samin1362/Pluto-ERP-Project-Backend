<?php

namespace Tests\Feature\Purchases;

use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\JournalEntry;
use App\Models\Ledger;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\StockMovement;
use App\Models\TaxRule;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** POST /purchase-bills/{id}/post (06 §4, §8; BR-001/004/009/017/019/053; TC-PUR-002/003/005, TC-CON-003; P4.3-BE-01). */
class PurchaseBillPostingTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $ngj;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->warehouse('WH-TEJ', 'Tejgaon warehouse');
        $this->ngj = $this->warehouse('WH-NGJ', 'Narayanganj depot');
        $this->oil = $this->product('Soybean oil 5L jar');
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        if ($method === 'POST' && ! isset($headers['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function draft(array $lines, array $over = []): array
    {
        return $this->req('POST', '/api/v1/purchase-bills', $over + ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'SB-'.Str::random(6),
            'bill_date' => $this->today(), 'warehouse_id' => $this->ngj->id, 'lines' => $lines])->assertCreated()->json('data');
    }

    private function postBill(int $id, array $body = [], array $headers = [], ?string $token = null)
    {
        return $this->req('POST', "/api/v1/purchase-bills/{$id}/post", $body, $headers, $token);
    }

    private function ledger(string $code): Ledger
    {
        return Ledger::where('code', $code)->firstOrFail();
    }

    private function stock(Product $p): array
    {
        $b = DB::table('stock_balances')->where('product_id', $p->id)->where('warehouse_id', $this->ngj->id)->first();

        return [$b->qty_on_hand, $b->total_value, $b->avg_unit_cost];
    }

    public function test_tc_pur_002_740_jars_into_narayanganj(): void
    {
        $bill = $this->draft([['product_id' => $this->oil->id, 'quantity' => '740', 'unit_cost' => '846.00']]);
        $r = $this->postBill($bill['id'])->assertOk();
        $r->assertJsonPath('data.status', 'posted')->assertJsonPath('data.balance_due', '626040.00')->assertJsonPath('data.version', 2);
        $this->assertNotNull($r->json('data.posted_at'));
        $this->assertSame([['1301-02', '626040.00', '0.00'], ['2101-001', '0.00', '626040.00']],
            array_map(fn ($l) => [$l['ledger_code'], $l['debit'], $l['credit']], $r->json('data.journal')));

        $entry = JournalEntry::findOrFail(PurchaseBill::findOrFail($bill['id'])->journal_entry_id);
        $this->assertSame([$bill['number'], 'purchase_bill', 'purchase_bills', $bill['id'], false],
            [$entry->voucher_number, $entry->entry_type, $entry->source_type, $entry->source_id, $entry->is_system_generated]);
        $this->assertSame(['740.000', '626040.00', '846.0000'], $this->stock($this->oil));
        $m = StockMovement::sole();
        $this->assertSame(['purchase_in', $entry->id, 'purchase_bills', $bill['id']], [$m->movement_type, $m->journal_entry_id, $m->source_type, $m->source_id]);
        $this->assertTrue(AuditLog::where('action', 'posted')->where('auditable_type', 'purchase_bills')->where('auditable_id', $bill['id'])->exists());
        // The supplier now shows the payable.
        $this->req('GET', "/api/v1/parties/{$this->supplier->id}")->assertJsonPath('data.balance', '626040.00')->assertJsonPath('data.balance_side', 'cr');
    }

    public function test_tc_pur_003_the_average_cost_moves_with_the_second_bill(): void
    {
        $this->postBill($this->draft([['product_id' => $this->oil->id, 'quantity' => '100', 'unit_cost' => '800']])['id'])->assertOk();
        $this->postBill($this->draft([['product_id' => $this->oil->id, 'quantity' => '50', 'unit_cost' => '860']])['id'])->assertOk();
        $this->assertSame(['150.000', '123000.00', '820.0000'], $this->stock($this->oil));
    }

    public function test_tc_pur_005_services_and_tax_post_where_they_belong(): void
    {
        $transport = $this->product('Carriage inward', 'pcs', '0.00', 'service');
        $vat = TaxRule::create(['name' => 'Input VAT 15%', 'rate' => '15', 'applies_to' => 'purchases', 'is_recoverable' => true, 'is_active' => true, 'ledger_id' => $this->ledger('1402')->id]);
        $ait = TaxRule::create(['name' => 'AIT 5%', 'rate' => '5', 'applies_to' => 'purchases', 'is_recoverable' => false, 'is_active' => true, 'ledger_id' => $this->ledger('1402')->id]);
        $rice = $this->product('Rice 50kg', 'bag');
        $bill = $this->draft([
            ['product_id' => $this->oil->id, 'quantity' => '10', 'unit_cost' => '800', 'tax_rule_id' => $vat->id],   // 8000 + recoverable 1200
            ['product_id' => $rice->id, 'quantity' => '10', 'unit_cost' => '2000', 'tax_rule_id' => $ait->id],       // 20000 + 1000 into cost
            ['product_id' => $transport->id, 'quantity' => '1', 'unit_cost' => '1500'],                              // service: expense, no stock
        ]);
        $journal = collect($this->postBill($bill['id'])->assertOk()->json('data.journal'))->map(fn ($l) => [$l['ledger_code'], $l['debit'], $l['credit']])->all();
        $this->assertEqualsCanonicalizing([['1301-02', '29000.00', '0.00'], ['5101', '1500.00', '0.00'], ['1402', '1200.00', '0.00'], ['2101-001', '0.00', '31700.00']], $journal);
        $this->assertSame(['10.000', '21000.00', '2100.0000'], $this->stock($rice), 'non-recoverable AIT is cost');
        $this->assertSame(2, StockMovement::count(), 'no movement for the service');
    }

    public function test_a_foreign_bill_posts_in_base_currency_and_keeps_the_foreign_amount(): void
    {
        $bill = $this->draft([['product_id' => $this->oil->id, 'quantity' => '10', 'unit_cost' => '20.00']], ['currency_code' => 'USD', 'exchange_rate' => '110.5']);
        $this->postBill($bill['id'])->assertOk()->assertJsonPath('data.total', '22100.00')->assertJsonPath('data.total_foreign', '200.00');
        $supplierLine = DB::table('journal_lines')->where('ledger_id', $this->supplier->ledger_id)->first();
        $this->assertSame(['22100.00', 'USD', '200.00', '110.500000'], [$supplierLine->credit, $supplierLine->foreign_currency_code, $supplierLine->foreign_amount, $supplierLine->exchange_rate]);
        $this->assertSame(['10.000', '22100.00', '2210.0000'], $this->stock($this->oil));
    }

    public function test_b_r_009_and_tc_con_003_posting_happens_once(): void
    {
        $bill = $this->draft([['product_id' => $this->oil->id, 'quantity' => '10', 'unit_cost' => '800']]);
        $key = (string) Str::uuid();
        $first = $this->postBill($bill['id'], [], ['Idempotency-Key' => $key])->assertOk();
        $replay = $this->postBill($bill['id'], [], ['Idempotency-Key' => $key])->assertOk();
        $this->assertEquals($first->json('data'), $replay->json('data'), 'the stored response is replayed (key order aside)');
        $this->assertProblem($this->postBill($bill['id']), 409, 'already_posted');
        $this->assertSame([1, 1], [JournalEntry::count(), StockMovement::count()]);
        $this->assertProblem($this->req('PUT', "/api/v1/purchase-bills/{$bill['id']}", ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'X', 'bill_date' => $this->today(),
            'warehouse_id' => $this->ngj->id, 'lines' => [['product_id' => $this->oil->id, 'quantity' => '1', 'unit_cost' => '1']]], ['If-Match' => 'W/"2"']), 409, 'not_draft');
    }

    public function test_refused_postings_change_nothing(): void
    {
        $bill = $this->draft([['product_id' => $this->oil->id, 'quantity' => '10', 'unit_cost' => '800']]);

        $this->supplier->update(['status' => 'inactive']);
        $this->assertProblem($this->postBill($bill['id']), 422, 'inactive_reference')->assertJsonPath('errors.party_id.0', 'Bengal Edible Oils is inactive');
        $this->supplier->update(['status' => 'active']);

        AccountingPeriod::query()->update(['status' => 'locked']);
        $this->assertProblem($this->postBill($bill['id']), 422, 'period_locked');
        AccountingPeriod::query()->update(['status' => 'open']);

        $this->userWithRole('store');
        $this->assertProblem($this->postBill($bill['id'], token: $this->login('store@meghna.example')), 403, 'permission_denied');

        $this->assertSame(['draft', 0, 0], [PurchaseBill::findOrFail($bill['id'])->status, JournalEntry::count(), StockMovement::count()]);
        $this->postBill($bill['id'])->assertOk();
    }

    public function test_b_r_053_a_backdated_bill_needs_confirmation(): void
    {
        $date = CarbonImmutable::parse(DB::table('accounting_periods')->where('company_id', $this->books->id)->where('status', 'open')->min('start_date'));
        if ($date->diffInDays(CarbonImmutable::now('Asia/Dhaka')) < 8) {
            $this->markTestSkipped('Needs an open period more than a week old.');
        }
        $this->useBooks();
        CompanySetting::query()->update(['backdate_warning_days' => 7]);
        $bill = $this->draft([['product_id' => $this->oil->id, 'quantity' => '1', 'unit_cost' => '800']], ['bill_date' => $date->toDateString()]);
        $this->assertProblem($this->postBill($bill['id']), 422, 'backdated_confirmation_required')->assertJsonPath('errors.confirm_backdated.0', 'Confirm the back-dated bill');
        $this->postBill($bill['id'], ['confirm_backdated' => true])->assertOk()->assertJsonPath('data.status', 'posted');
    }
}
