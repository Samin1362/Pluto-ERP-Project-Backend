<?php

namespace Tests\Feature\Sales;

use App\Domain\Purchases\PurchaseBillPostingService;
use App\Domain\Purchases\PurchaseBillService;
use App\Models\AccountingPeriod;
use App\Models\AccountMapping;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\JournalEntry;
use App\Models\Ledger;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\TaxRule;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** POST /sales-invoices/{id}/post (06 §4, §5; BR-001/004/009/014/016/053/064; TC-SAL-003/004/005, TC-ACC-024; P5.1-BE-03). */
class SalesInvoicePostingTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $karim;

    private Party $supplier;

    private Warehouse $tej;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->supplier = $this->supplier();
        $this->tej = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
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

    /** Stock in through a posted purchase bill, so the books and the stock agree (BooksAreConsistent). */
    private function receive(Product $p, string $qty, string $cost): void
    {
        $this->useBooks();
        Auth::setUser(User::where('email', 'owner@meghna.example')->firstOrFail());
        $bill = app(PurchaseBillService::class)->createDraft(['party_id' => $this->supplier->id, 'supplier_bill_no' => 'SB-'.Str::random(6), 'bill_date' => $this->today(),
            'warehouse_id' => $this->tej->id, 'lines' => [['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => $cost]]]);
        app(PurchaseBillPostingService::class)->post($bill);
    }

    private function draft(array $lines, array $over = []): array
    {
        return $this->req('POST', '/api/v1/sales-invoices', $over + ['party_id' => $this->karim->id, 'invoice_date' => $this->today(),
            'warehouse_id' => $this->tej->id, 'lines' => $lines])->assertCreated()->json('data');
    }

    private function postInvoice(int $id, array $body = [], array $headers = [], ?string $token = null)
    {
        return $this->req('POST', "/api/v1/sales-invoices/{$id}/post", $body, $headers, $token);
    }

    private function ledgerCode(Ledger|int $l): string
    {
        return Ledger::whereKey($l instanceof Ledger ? $l->id : $l)->value('code');
    }

    private function onHand(Product $p): array
    {
        $b = DB::table('stock_balances')->where('product_id', $p->id)->where('warehouse_id', $this->tej->id)->first();

        return [$b->qty_on_hand, $b->total_value, $b->avg_unit_cost];
    }

    public function test_tc_sal_004_the_e5_example_posts_exactly(): void
    {
        // 06 §5 worked example (INV-2026-0587): five stocked lines at their average costs and a delivery service.
        $rice = $this->product('Miniket rice 25kg', 'bag', '2340.00');
        $tea = $this->product('Ispahani tea 400g', 'pcs', '255.00');
        $soap = $this->product('Lux soap 150g', 'pcs', '220.00');
        $lentils = $this->product('Red lentils', 'kg', '125.00');
        $delivery = $this->product('Delivery trip', 'pcs', '150.00', 'service');
        foreach ([[$this->oil, '60', '845.00'], [$rice, '40', '2150.00'], [$tea, '500', '218.00'], [$soap, '300', '186.00'], [$lentils, '80', '108.00']] as [$p, $q, $c]) {
            $this->receive($p, $q, $c);
        }
        $entriesBefore = JournalEntry::count();
        $inv = $this->draft([
            ['product_id' => $this->oil->id, 'quantity' => '40'], ['product_id' => $rice->id, 'quantity' => '12'], ['product_id' => $tea->id, 'quantity' => '120'],
            ['product_id' => $soap->id, 'quantity' => '96'], ['product_id' => $lentils->id, 'quantity' => '6'], ['product_id' => $delivery->id, 'quantity' => '2'],
        ]);
        $r = $this->postInvoice($inv['id'])->assertOk();
        $r->assertJsonPath('data.status', 'posted')->assertJsonPath('data.payment_status', 'unpaid')->assertJsonPath('data.total', '118450.00')
            ->assertJsonPath('data.balance_due', '118450.00')->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.lines.0.unit_cost', '845.0000')->assertJsonPath('data.lines.0.cost_amount', '33800.00')
            ->assertJsonPath('data.lines.5.unit_cost', null)->assertJsonPath('data.lines.5.cost_amount', null);
        $this->assertNotNull($r->json('data.posted_at'));

        $journal = collect($r->json('data.journal'))->map(fn ($l) => [$l['ledger_code'], $l['debit'], $l['credit']])->all();
        $this->assertEqualsCanonicalizing([
            [$this->ledgerCode($this->karim->ledger_id), '118450.00', '0.00'],
            ['4101', '0.00', '118150.00'],
            ['4201', '0.00', '300.00'],
            ['5001', '104264.00', '0.00'],
            [$this->ledgerCode($this->tej->inventory_ledger_id), '0.00', '104264.00'],
        ], $journal);

        $entry = JournalEntry::findOrFail(SalesInvoice::findOrFail($inv['id'])->journal_entry_id);
        $this->assertSame([$inv['number'], 'sales_invoice', 'sales_invoices', $inv['id']], [$entry->voucher_number, $entry->entry_type, $entry->source_type, $entry->source_id]);
        $this->assertSame($entriesBefore + 1, JournalEntry::count());
        $this->assertSame(['20.000', '16900.00', '845.0000'], $this->onHand($this->oil), 'stock falls; the average stays');
        $this->assertSame(5, StockMovement::where('source_type', 'sales_invoices')->where('movement_type', 'sale_out')->where('journal_entry_id', $entry->id)->count(), 'none for the service');
        $this->assertTrue(AuditLog::where('action', 'posted')->where('auditable_type', 'sales_invoices')->where('auditable_id', $inv['id'])->exists());
        $this->req('GET', "/api/v1/parties/{$this->karim->id}")->assertJsonPath('data.balance', '118450.00')->assertJsonPath('data.balance_side', 'dr');
        $sales = collect($this->req('GET', '/api/v1/stock-movements?movement_type=sale_out')->assertOk()->json('data'));
        $this->assertSame([$inv['number']], $sales->pluck('source_number')->unique()->values()->all(), 'D9 names the invoice');
    }

    public function test_tax_and_rounding_post_to_their_ledgers(): void
    {
        $this->receive($this->oil, '20', '800.00');
        $vatLedger = Ledger::where('code', '2201')->first() ?? Ledger::where('code', '1402')->firstOrFail();
        $vat = TaxRule::create(['name' => 'VAT 7.5%', 'rate' => '7.5', 'applies_to' => 'sales', 'is_recoverable' => true, 'is_active' => true, 'ledger_id' => $vatLedger->id]);
        // 10 × 2,340 less 2 % = 22,932.00 net; VAT 1,719.90; total 24,651.90 → 24,652.00 (+0.10 rounding).
        $inv = $this->draft([['product_id' => $this->oil->id, 'quantity' => '10', 'unit_price' => '2340.00', 'discount_pct' => '2', 'tax_rule_id' => $vat->id]]);
        $journal = collect($this->postInvoice($inv['id'])->assertOk()->json('data.journal'))->map(fn ($l) => [$l['ledger_code'], $l['debit'], $l['credit']])->all();
        $this->assertEqualsCanonicalizing([
            [$this->ledgerCode($this->karim->ledger_id), '24652.00', '0.00'],
            ['4101', '0.00', '22932.00'],
            [$vatLedger->code, '0.00', '1719.90'],
            ['6799', '0.00', '0.10'],
            ['5001', '8000.00', '0.00'],
            [$this->ledgerCode($this->tej->inventory_ledger_id), '0.00', '8000.00'],
        ], $journal);
    }

    public function test_tc_sal_003_insufficient_stock_and_negative_stock_allowed(): void
    {
        $this->receive($this->oil, '18', '845.00');
        $inv = $this->draft([['product_id' => $this->oil->id, 'quantity' => '40']]);
        $r = $this->assertProblem($this->postInvoice($inv['id']), 422, 'insufficient_stock');
        $this->assertSame(['Only 18.000 on hand in Tejgaon warehouse'], $r->json('errors')['lines.0.quantity']);
        $this->assertSame(['draft', 0], [SalesInvoice::findOrFail($inv['id'])->status, StockMovement::where('source_type', 'sales_invoices')->count()]);

        CompanySetting::query()->update(['allow_negative_stock' => true]);
        $this->postInvoice($inv['id'])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->assertSame('-22.000', $this->onHand($this->oil)[0]);
    }

    public function test_tc_sal_005_posting_happens_once(): void
    {
        $this->receive($this->oil, '20', '845.00');
        $inv = $this->draft([['product_id' => $this->oil->id, 'quantity' => '10']]);
        $key = (string) Str::uuid();
        $first = $this->postInvoice($inv['id'], ['acknowledge_warnings' => ['PARTY_OVERDUE']], ['Idempotency-Key' => $key])->assertOk();
        $replay = $this->postInvoice($inv['id'], ['acknowledge_warnings' => ['PARTY_OVERDUE']], ['Idempotency-Key' => $key])->assertOk();
        $this->assertEquals($first->json('data'), $replay->json('data'), 'the stored response is replayed (key order aside)');
        $this->assertProblem($this->postInvoice($inv['id']), 409, 'already_posted');
        $this->assertSame([1, 1], [JournalEntry::where('entry_type', 'sales_invoice')->count(), StockMovement::where('source_type', 'sales_invoices')->count()]);
        $this->assertProblem($this->req('PUT', "/api/v1/sales-invoices/{$inv['id']}", ['party_id' => $this->karim->id, 'invoice_date' => $this->today(),
            'warehouse_id' => $this->tej->id, 'lines' => [['product_id' => $this->oil->id, 'quantity' => '1']]], ['If-Match' => 'W/"2"']), 409, 'not_draft');
        $this->assertProblem($this->req('DELETE', "/api/v1/sales-invoices/{$inv['id']}"), 409, 'not_draft');
    }

    public function test_tc_sal_006_over_the_credit_limit_still_posts(): void
    {
        $this->receive($this->oil, '20', '845.00');
        $this->karim->update(['credit_limit' => '1000.00']);
        $this->postInvoice($this->draft([['product_id' => $this->oil->id, 'quantity' => '10']])['id'])->assertOk()->assertJsonPath('data.status', 'posted');
    }

    public function test_refused_postings_change_nothing(): void
    {
        $this->receive($this->oil, '20', '845.00');
        $inv = $this->draft([['product_id' => $this->oil->id, 'quantity' => '10']]);
        $before = [JournalEntry::count(), StockMovement::count()];

        $this->karim->update(['status' => 'inactive']);
        $this->assertProblem($this->postInvoice($inv['id']), 422, 'inactive_reference')->assertJsonPath('errors.party_id.0', 'Karim Distributors is inactive');
        $this->karim->update(['status' => 'active']);

        AccountingPeriod::query()->update(['status' => 'locked']);
        $this->assertProblem($this->postInvoice($inv['id']), 422, 'period_locked');
        AccountingPeriod::query()->update(['status' => 'open']);

        // TC-ACC-024: COGS mapped to an income ledger never reaches the books (the K16 mapping screen refuses it too, P7).
        $cogs = AccountMapping::where('mapping_key', 'cogs')->value('ledger_id');
        AccountMapping::where('mapping_key', 'cogs')->update(['ledger_id' => Ledger::where('code', '4101')->value('id')]);
        $this->assertProblem($this->postInvoice($inv['id']), 422, 'mapping_missing')->assertJsonPath('mapping_key', 'cogs');
        AccountMapping::where('mapping_key', 'cogs')->update(['ledger_id' => $cogs]);

        $this->userWithRole('store');
        $this->assertProblem($this->postInvoice($inv['id'], token: $this->login('store@meghna.example')), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $this->assertProblem($this->postInvoice($inv['id'], token: $this->login('viewer@meghna.example')), 403, 'permission_denied');
        $this->assertProblem($this->postInvoice($inv['id'], ['receipt' => null, 'colour' => 'red']), 422, 'validation_failed');

        $this->assertSame(['draft', ...$before], [SalesInvoice::findOrFail($inv['id'])->status, JournalEntry::count(), StockMovement::count()]);
        $this->userWithRole('sales');
        $this->postInvoice($inv['id'], token: $this->login('sales@meghna.example'))->assertOk();

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->assertProblem($this->api('POST', "/api/v1/sales-invoices/{$inv['id']}/post", $this->login('padma@agro.example'), $other->id, [],
            ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
        $this->useBooks();
    }

    public function test_b_r_053_a_backdated_invoice_needs_confirmation(): void
    {
        $date = CarbonImmutable::parse(DB::table('accounting_periods')->where('company_id', $this->books->id)->where('status', 'open')->min('start_date'));
        if ($date->diffInDays(CarbonImmutable::now('Asia/Dhaka')) < 8) {
            $this->markTestSkipped('Needs an open period more than a week old.');
        }
        $this->receive($this->oil, '5', '845.00');
        $this->useBooks();
        CompanySetting::query()->update(['backdate_warning_days' => 7]);
        $inv = $this->draft([['product_id' => $this->oil->id, 'quantity' => '1']], ['invoice_date' => $date->toDateString()]);
        $this->assertProblem($this->postInvoice($inv['id']), 422, 'backdated_confirmation_required')->assertJsonPath('errors.confirm_backdated.0', 'Confirm the back-dated invoice');
        $this->postInvoice($inv['id'], ['confirm_backdated' => true])->assertOk()->assertJsonPath('data.status', 'posted');
    }
}
