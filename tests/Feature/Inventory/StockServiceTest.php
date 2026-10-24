<?php

namespace Tests\Feature\Inventory;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\BooksIntegrity;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Inventory\StockService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\CompanySetting;
use App\Models\Ledger;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * StockService on MySQL (BR-014, BR-016, BR-017; R-16, R-17; TC-INV-001; backlog P4.1-BE-06). Each movement is
 * journalled the way the posting services will (inventory against suppliers/COGS), so BooksAreConsistent checks
 * stock against movements and against the inventory ledgers after every test.
 */
class StockServiceTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private StockService $stock;

    private Warehouse $tej;

    private Warehouse $ngj;

    private Product $oil;

    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->stock = app(StockService::class);
        $this->tej = $this->warehouse('WH-TEJ', 'Tejgaon warehouse');
        $this->ngj = $this->warehouse('WH-NGJ', 'Narayanganj depot');
        $this->oil = $this->product('Soybean oil 5L');
        $this->rice = $this->product('Rice 50kg', 'bag');
    }

    /** Stock in, journalled Dr warehouse inventory / Cr suppliers suspense (plus any R-16 true-up against COGS). */
    private function in(Product $p, Warehouse $w, string $qty, string $value, string $date = '2026-07-05')
    {
        return DB::transaction(function () use ($p, $w, $qty, $value, $date) {
            $after = $this->stock->stockIn($p, $w, Quantity::of($qty), Money::of($value), $w, $date);
            $b = (new JournalBuilder)->debit($w->inventory_ledger_id, Money::of($value))->credit(Ledger::where('code', '9999')->value('id'), Money::of($value))
                ->debit($w->inventory_ledger_id, $after->trueUp)->credit(app(AccountMapper::class)->ledgerFor('cogs'), $after->trueUp);
            app(PostingService::class)->persist($b, null, 'stock_adjustment', $date);

            return $after;
        });
    }

    /** Stock out at cost, journalled Dr COGS / Cr warehouse inventory. */
    private function out(Product $p, Warehouse $w, string $qty, string $date = '2026-07-07'): Money
    {
        return DB::transaction(function () use ($p, $w, $qty, $date) {
            $cost = $this->stock->stockOut($p, $w, Quantity::of($qty), $w, $date);
            app(PostingService::class)->persist((new JournalBuilder)->debit(app(AccountMapper::class)->ledgerFor('cogs'), $cost)->credit($w->inventory_ledger_id, $cost),
                null, 'stock_adjustment', $date);

            return $cost;
        });
    }

    private function balance(Product $p, Warehouse $w): array
    {
        $b = DB::table('stock_balances')->where('product_id', $p->id)->where('warehouse_id', $w->id)->first();

        return [$b->qty_on_hand, $b->total_value, $b->avg_unit_cost];
    }

    public function test_stock_in_then_out_at_the_moving_average(): void
    {
        $this->in($this->oil, $this->tej, '100', '80000');
        $this->in($this->oil, $this->tej, '50', '43000', '2026-07-06');
        $this->assertSame(['150.000', '123000.00', '820.0000'], $this->balance($this->oil, $this->tej), 'TC-PUR-003');

        $this->assertSame('32800.00', (string) $this->out($this->oil, $this->tej, '40'), 'BR-016: 40 × 820.0000');
        $this->assertSame(['110.000', '90200.00', '820.0000'], $this->balance($this->oil, $this->tej));

        $m = StockMovement::orderBy('id')->get();
        $this->assertSame(['purchase_in', 'purchase_in', 'sale_out'], $m->pluck('movement_type')->all());
        $this->assertSame(['100.000', '150.000', '110.000'], $m->map(fn ($x) => (string) $x->qty_balance_after)->all());
        $this->assertSame('820.0000', (string) $m[2]->unit_cost);
        $this->assertSame($this->tej->getMorphClass(), $m[0]->source_type);
    }

    public function test_insufficient_stock_is_refused_with_per_line_detail(): void
    {
        $this->in($this->oil, $this->tej, '18', '15210');
        try {
            $this->stock->stockOutMany([
                0 => ['product' => $this->oil, 'warehouse' => $this->tej, 'qty' => Quantity::of('10')],
                1 => ['product' => $this->rice, 'warehouse' => $this->tej, 'qty' => Quantity::of('3')],
                2 => ['product' => $this->oil, 'warehouse' => $this->tej, 'qty' => Quantity::of('10')], // 20 of 18 across the two oil lines
            ], $this->tej, '2026-07-06');
            $this->fail('shortage accepted');
        } catch (ApiProblem $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
            $this->assertSame(['lines.1.quantity', 'lines.2.quantity'], array_keys($e->errors));
            $this->assertSame(['Only 0.000 on hand in Tejgaon warehouse'], $e->errors['lines.1.quantity']);
            $this->assertSame('20.000', $e->extra['shortages'][1]['requested']);
        }
        $this->assertSame(['18.000', '15210.00', '845.0000'], $this->balance($this->oil, $this->tej), 'nothing issued');
        $this->assertSame(1, StockMovement::count());
    }

    public function test_tc_inv_008_negative_stock_when_allowed_and_the_r16_true_up(): void
    {
        CompanySetting::query()->update(['allow_negative_stock' => true]);
        $this->in($this->oil, $this->tej, '1', '100', '2026-07-01');
        $this->out($this->oil, $this->tej, '1', '2026-07-01');
        $this->assertSame('500.00', (string) $this->out($this->oil, $this->tej, '5', '2026-07-02'), 'issued at the last average (R-16)');
        $this->assertSame(['-5.000', '-500.00', '100.0000'], $this->balance($this->oil, $this->tej));

        $after = $this->in($this->oil, $this->tej, '10', '1200', '2026-07-03');
        $this->assertSame('-100.00', (string) $after->trueUp, 'Dr COGS 100 / Cr inventory 100');
        $this->assertSame(['5.000', '600.00', '120.0000'], $this->balance($this->oil, $this->tej));
        $cogs = DB::table('journal_lines')->where('ledger_id', app(AccountMapper::class)->ledgerFor('cogs')->id)->selectRaw('SUM(debit) - SUM(credit) n')->value('n');
        $this->assertSame('700.00', $cogs, '100 + 500 issued + 100 true-up');
    }

    public function test_balances_are_per_warehouse_and_locked_in_a_fixed_order(): void
    {
        $this->in($this->rice, $this->ngj, '10', '21500', '2026-07-01');
        $this->in($this->rice, $this->tej, '10', '23000', '2026-07-01');
        $this->assertSame('2150.0000', $this->balance($this->rice, $this->ngj)[2]);
        $this->assertSame('2300.0000', $this->balance($this->rice, $this->tej)[2]);

        DB::enableQueryLog();
        $locked = $this->stock->lock([[$this->rice->id, $this->tej->id], [$this->oil->id, $this->ngj->id], [$this->oil->id, $this->tej->id], [$this->rice->id, $this->tej->id]]);
        $order = array_keys($locked);
        $sorted = $order;
        usort($sorted, fn ($a, $b) => array_map('intval', explode(':', $a)) <=> array_map('intval', explode(':', $b)));
        $this->assertSame($sorted, $order, 'product_id, warehouse_id ascending, duplicates once');
        $this->assertCount(3, $order);
        $this->assertNotEmpty(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'for update')));
        $this->assertSame('0.000', $locked["{$this->oil->id}:{$this->ngj->id}"]->qty_on_hand, 'missing rows are created');
    }

    public function test_services_have_no_stock(): void
    {
        $service = $this->product('Delivery charge', 'pcs', '500.00', 'service');
        $this->expectException(LogicException::class);
        $this->stock->stockIn($service, $this->tej, Quantity::of('1'), Money::of('1'), $this->tej, '2026-07-01');
    }

    public function test_the_books_check_catches_a_corrupted_balance(): void
    {
        $this->in($this->oil, $this->tej, '10', '8450');
        DB::table('stock_balances')->where('product_id', $this->oil->id)->update(['qty_on_hand' => '11.000', 'total_value' => '9000.00']);
        $failures = app(BooksIntegrity::class)->failures($this->books->id);
        $this->assertCount(3, $failures, implode("\n", $failures)); // quantity, value, and the inventory ledger vs stock value
        DB::table('stock_balances')->where('product_id', $this->oil->id)->update(['qty_on_hand' => '10.000', 'total_value' => '8450.00']);
    }
}
