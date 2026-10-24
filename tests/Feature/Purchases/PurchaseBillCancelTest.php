<?php

namespace Tests\Feature\Purchases;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Inventory\StockService;
use App\Domain\Shared\Quantity;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** POST /purchase-bills/{id}/cancel (06 §15; BR-003, BR-014, BR-024; R-17; TC-PUR-010; backlog P4.3-BE-02). */
class PurchaseBillCancelTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $wh;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->wh = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar');
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

    private function postedBill(string $qty, string $cost): array
    {
        $bill = $this->req('POST', '/api/v1/purchase-bills', ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'SB-'.Str::random(6),
            'bill_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => $qty, 'unit_cost' => $cost]]])->assertCreated()->json('data');
        $this->req('POST', "/api/v1/purchase-bills/{$bill['id']}/post")->assertOk();

        return $bill;
    }

    /** A sale's stock side, journalled Dr COGS / Cr inventory (sales invoices arrive in P5). */
    private function sell(string $qty): void
    {
        $this->useBooks();
        DB::transaction(function () use ($qty) {
            $cost = app(StockService::class)->stockOut($this->oil, $this->wh, Quantity::of($qty), $this->wh, CarbonImmutable::now('Asia/Dhaka')->toDateString());
            app(PostingService::class)->persist((new JournalBuilder)->debit(app(AccountMapper::class)->ledgerFor('cogs'), $cost)->credit($this->wh->inventory_ledger_id, $cost),
                null, 'stock_adjustment', CarbonImmutable::now('Asia/Dhaka')->toDateString());
        });
    }

    private function cancel(int $id, array $body = ['reason' => 'entered_by_mistake'], ?string $token = null)
    {
        return $this->req('POST', "/api/v1/purchase-bills/{$id}/cancel", $body, token: $token);
    }

    private function stock(): array
    {
        $b = DB::table('stock_balances')->where('product_id', $this->oil->id)->where('warehouse_id', $this->wh->id)->first();

        return [$b->qty_on_hand, $b->total_value, $b->avg_unit_cost];
    }

    public function test_cancel_reverses_the_journal_and_the_stock_and_keeps_the_number(): void
    {
        $bill = $this->postedBill('740', '846.00');
        $r = $this->cancel($bill['id'], ['reason' => 'duplicate', 'note' => 'Entered twice'])->assertOk();
        $r->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.number', $bill['number'])->assertJsonPath('data.balance_due', '0.00');

        $b = PurchaseBill::findOrFail($bill['id']);
        $this->assertSame(['duplicate', true], [$b->cancel_reason, $b->cancelled_at !== null]);
        $original = JournalEntry::findOrFail($b->journal_entry_id);
        $cancel = JournalEntry::with('lines.ledger')->findOrFail($b->cancellation_entry_id);
        $fy = FiscalYear::findOrFail($original->fiscal_year_id)->start_date->year;
        $this->assertSame(['reversed', 'cancellation', "SJ-{$fy}-0001", $original->id, true],
            [$original->status, $cancel->entry_type, $cancel->voucher_number, $cancel->reverses_entry_id, $cancel->is_system_generated]);
        $this->assertSame([['2101-001', '626040.00', '0.00'], ['1301-01', '0.00', '626040.00']],
            $cancel->lines->map(fn ($l) => [$l->ledger->code, (string) $l->debit, (string) $l->credit])->all(), 'every side swapped');
        $this->assertSame(['0.000', '0.00'], array_slice($this->stock(), 0, 2));
        $m = StockMovement::where('movement_type', 'reversal')->sole();
        $this->assertSame(['740.000', '626040.00', $cancel->id], [(string) $m->quantity_out, (string) $m->value_out, $m->journal_entry_id]);
        $this->req('GET', "/api/v1/parties/{$this->supplier->id}")->assertJsonPath('data.balance', '0.00');
        $this->assertTrue(AuditLog::where('action', 'cancelled')->where('auditable_type', 'purchase_bills')->where('auditable_id', $bill['id'])->exists());
    }

    public function test_tc_pur_010_partly_sold_stock_blocks_the_cancel_unless_negative_stock_is_allowed(): void
    {
        $bill = $this->postedBill('10', '100');
        $this->sell('4');
        $this->assertProblem($this->cancel($bill['id']), 422, 'insufficient_stock')->assertJsonPath('errors', ['lines.0.quantity' => ['Only 6.000 on hand in Tejgaon warehouse']]);
        $this->assertSame('posted', PurchaseBill::findOrFail($bill['id'])->status, 'nothing changed');
        $this->assertSame(0, StockMovement::where('movement_type', 'reversal')->count());

        CompanySetting::query()->update(['allow_negative_stock' => true]);
        $this->cancel($bill['id'])->assertOk();
        $this->assertSame(['-4.000', '-400.00', '100.0000'], $this->stock(), 'back out at the bill cost');
    }

    public function test_r17_the_value_never_goes_below_zero_and_the_difference_goes_to_stock_adjustment(): void
    {
        $a = $this->postedBill('10', '100');   // 1,000.00
        $this->postedBill('90', '10');         // 900.00 → 100 units, 1,900.00, avg 19
        $this->sell('85');                     // 15 left, 285.00
        $this->cancel($a['id'])->assertOk();
        $this->assertSame(['5.000', '0.00', '0.0000'], $this->stock());
        $adjust = DB::table('journal_lines')->where('ledger_id', app(AccountMapper::class)->ledgerFor('stock_adjustment')->id)->selectRaw('SUM(credit) - SUM(debit) n')->value('n');
        $this->assertSame('715.00', $adjust, '1,000.00 put in − 285.00 left to take out');
    }

    public function test_refusals(): void
    {
        $bill = $this->postedBill('1', '100');
        $this->assertProblem($this->cancel($bill['id'], []), 422, 'validation_failed')->assertJsonPath('errors.reason.0', 'Choose why it is cancelled');
        $this->assertProblem($this->cancel($bill['id'], ['reason' => 'changed_my_mind']), 422, 'validation_failed');

        $this->userWithRole('store');
        $this->assertProblem($this->cancel($bill['id'], token: $this->login('store@meghna.example')), 403, 'permission_denied');

        AccountingPeriod::query()->update(['status' => 'locked']);
        $this->assertProblem($this->cancel($bill['id']), 422, 'period_locked');
        AccountingPeriod::query()->update(['status' => 'open']);

        $draft = $this->req('POST', '/api/v1/purchase-bills', ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'D-1', 'bill_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->oil->id, 'quantity' => '1', 'unit_cost' => '1']]])->json('data');
        $this->assertProblem($this->cancel($draft['id']), 409, 'invalid_transition');

        $this->cancel($bill['id'])->assertOk();
        $this->assertProblem($this->cancel($bill['id']), 409, 'already_reversed');
        $this->assertSame(1, JournalEntry::where('entry_type', 'cancellation')->count());
    }

    public function test_allocated_payments_become_advances(): void
    {
        $this->markTestIncomplete('BR-024: allocations turn into supplier advances once supplier payments and bill_allocations exist (P8); the hook is PurchaseBillCancellationService::releaseAllocations.');
    }
}
