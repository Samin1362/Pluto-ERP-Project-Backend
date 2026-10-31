<?php

namespace App\Domain\Inventory;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PeriodGuard;
use App\Domain\Accounting\PostingService;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use App\Models\JournalEntry;
use App\Models\Ledger;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stock adjustments (D10; 06 §11.3; BR-029, BR-009, BR-004; TC-INV-002, TC-INV-003; backlog P11.1-BE-02).
 *
 * A draft lists, per product, the counted quantity; the system quantity on hand in the warehouse is read when the draft
 * is saved and kept with it, and the difference (counted − system) is valued at the warehouse's average cost. Posting
 * re-reads the system quantity: if any line changed since the count the user must recount (422, per line). A shortage
 * issues adjustment_out at the average cost (Dr stock adjustment / Cr warehouse inventory); a gain receives
 * adjustment_in at the average cost (Dr warehouse inventory / Cr stock adjustment). One entry per adjustment; a gain with
 * no cost to value it at posts movements only. Reversal (inventory.approve) mirrors the entry and the movements, dated
 * today.
 */
final class StockAdjustmentService
{
    public const REASONS = ['count_difference', 'damaged', 'expired', 'theft_or_loss', 'free_samples'];

    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly StockService $stock,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $in): StockAdjustment
    {
        return DB::transaction(function () use ($in) {
            [$warehouse, $lines] = $this->prepare($in);
            $period = $this->periods->assertOpen($in['adjustment_date']);
            $adj = new StockAdjustment(['status' => 'draft']);
            $adj->number = $this->numbers->next('stock_adjustment', $period->fiscal_year_id)['number'];
            $this->fill($adj, $warehouse, $lines, $in);
            $adj->save();
            $this->saveLines($adj, $lines);
            $this->audit->record('created', $adj, null, ['number' => $adj->number, 'warehouse' => $warehouse->name, 'net_value' => (string) $adj->net_value]);

            return $adj->refresh()->load('lines.product.unit');
        });
    }

    public function update(StockAdjustment $adj, array $in, int $expectedVersion): StockAdjustment
    {
        return DB::transaction(function () use ($adj, $in, $expectedVersion) {
            $adj = StockAdjustment::whereKey($adj->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($adj);
            if ((int) $adj->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $adj->lock_version]);
            }
            [$warehouse, $lines] = $this->prepare($in);
            $this->periods->assertOpen($in['adjustment_date']);
            $old = ['net_value' => (string) $adj->net_value, 'warehouse_id' => $adj->warehouse_id, 'reason' => $adj->reason];
            $this->fill($adj, $warehouse, $lines, $in);
            $adj->lines()->delete();
            $adj->save();
            $this->saveLines($adj, $lines);
            $this->audit->record('updated', $adj, $old, ['net_value' => (string) $adj->net_value, 'warehouse_id' => $adj->warehouse_id, 'reason' => $adj->reason]);

            return $adj->load('lines.product.unit');
        });
    }

    public function delete(StockAdjustment $adj): void
    {
        DB::transaction(function () use ($adj) {
            $adj = StockAdjustment::whereKey($adj->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($adj);
            $adj->lines()->delete();
            $adj->delete();
            $this->audit->record('voided_number', $adj, ['number' => $adj->number], ['reason' => 'draft deleted']);
            $this->audit->record('deleted', $adj, ['number' => $adj->number, 'net_value' => (string) $adj->net_value]);
        });
    }

    public function post(StockAdjustment $adj): StockAdjustment
    {
        return DB::transaction(function () use ($adj) {
            $adj = StockAdjustment::with('lines')->whereKey($adj->id)->lockForUpdate()->firstOrFail();
            if ($adj->status !== 'draft') {
                throw new ApiProblem('already_posted', "{$adj->number} is already {$adj->status}.");
            }
            $this->periods->assertOpen($adj->adjustment_date);
            $warehouse = Warehouse::findOrFail($adj->warehouse_id);
            $products = Product::withTrashed()->with('unit')->whereIn('id', $adj->lines->pluck('product_id'))->get()->keyBy('id');
            $balances = $this->stock->lock($adj->lines->map(fn ($l) => [$l->product_id, $warehouse->id])->all());

            // 06 §11.3: the system quantity is re-read; a count made against an older quantity must be redone.
            $errors = [];
            foreach ($adj->lines->values() as $i => $l) {
                $now = Quantity::of($balances["{$l->product_id}:{$warehouse->id}"]->qty_on_hand);
                if (! $now->isEqualTo($l->system_qty)) {
                    $errors["lines.{$i}.counted_qty"] = ["{$products[$l->product_id]->name}: the system quantity is now ".self::qty($now)
                        .' (it was '.self::qty($l->system_qty).' when counted); recount and save again'];
                }
            }
            if ($errors) {
                throw new ApiProblem('validation_failed', 'Stock moved since the count. Recount the lines shown and save the adjustment again.', errors: $errors);
            }

            $adjLedger = $this->mapper->ledgerFor('stock_adjustment');
            $b = new JournalBuilder;
            $net = Money::zero();
            foreach ($adj->lines as $l) {
                $diff = Quantity::of($l->difference_qty);
                $product = $products[$l->product_id];
                if ($diff->isZero()) {
                    continue;
                }
                $avg = UnitCost::of($balances["{$l->product_id}:{$warehouse->id}"]->avg_unit_cost);
                if ($diff->isNegative()) {
                    $cost = $this->stock->stockOut($product, $warehouse, $diff->abs(), $adj, $adj->adjustment_date, 'adjustment_out', $l->id, true);
                    $b->debit($adjLedger, $cost)->credit($warehouse->inventory_ledger_id, $cost, ['warehouse_id' => $warehouse->id]);
                    $value = $cost->negated();
                } else {
                    $value = self::value($diff, $avg);
                    $after = $this->stock->stockIn($product, $warehouse, $diff, $value, $adj, $adj->adjustment_date, 'adjustment_in', $l->id);
                    $b->debit($warehouse->inventory_ledger_id, $value, ['warehouse_id' => $warehouse->id])->credit($adjLedger, $value);
                    if (! $after->trueUp->isZero()) { // R-16: a gain that covers negative stock trues up COGS
                        $b->debit($warehouse->inventory_ledger_id, $after->trueUp, ['warehouse_id' => $warehouse->id])->credit($this->mapper->ledgerFor('cogs'), $after->trueUp);
                    }
                }
                $l->forceFill(['unit_cost' => $avg, 'value' => $value])->save();
                $net = $net->plus($value);
            }
            $entry = null;
            if ($b->totalDebit()->isPositive()) {
                $entry = $this->posting->persist($b, $adj, 'stock_adjustment', $adj->adjustment_date, $adj->number,
                    'Stock adjustment at '.$warehouse->name.' ('.str_replace('_', ' ', $adj->reason).')', null, $warehouse->branch_id);
                $this->stock->linkJournal($adj, $entry->id);
            }
            $adj->forceFill(['status' => 'posted', 'net_value' => $net, 'journal_entry_id' => $entry?->id, 'posted_at' => now(), 'posted_by' => auth()->id()])->save();
            $this->audit->record('posted', $adj, ['status' => 'draft'], ['status' => 'posted', 'journal_entry_id' => $entry?->id, 'net_value' => (string) $net]);

            return $adj->refresh()->load('lines.product.unit');
        }, attempts: 2);
    }

    /** Mirror entry and movements, dated today (inventory.approve). Gains are taken back at the value they came in at. */
    public function reverse(StockAdjustment $adj, string $reason): StockAdjustment
    {
        return DB::transaction(function () use ($adj, $reason) {
            $adj = StockAdjustment::with('lines')->whereKey($adj->id)->lockForUpdate()->firstOrFail();
            if ($adj->status === 'reversed') {
                throw new ApiProblem('already_reversed', "{$adj->number} is already reversed.");
            }
            if ($adj->status !== 'posted') {
                throw new ApiProblem('invalid_transition', "{$adj->number} is a draft; delete it instead of reversing.", extra: ['status' => $adj->status]);
            }
            $date = CarbonImmutable::now('Asia/Dhaka')->toDateString();
            $this->periods->assertOpen($date);
            $warehouse = Warehouse::findOrFail($adj->warehouse_id);
            $products = Product::withTrashed()->whereIn('id', $adj->lines->pluck('product_id'))->get()->keyBy('id');
            $moves = StockMovement::where('source_type', $adj->getMorphClass())->where('source_id', $adj->id)->whereIn('movement_type', ['adjustment_in', 'adjustment_out'])
                ->get()->keyBy('source_line_id');
            $this->stock->lock($adj->lines->filter(fn ($l) => isset($moves[$l->id]))->map(fn ($l) => [$l->product_id, $warehouse->id])->values()->all());

            $b = new JournalBuilder;
            $original = $adj->journal_entry_id ? JournalEntry::with('lines')->whereKey($adj->journal_entry_id)->lockForUpdate()->firstOrFail() : null;
            foreach ($original?->lines ?? [] as $l) { // swap every side
                $meta = array_filter(['warehouse_id' => $l->warehouse_id]);
                $b->debit($l->ledger_id, $l->credit, $meta)->credit($l->ledger_id, $l->debit, $meta);
            }
            foreach ($adj->lines->values() as $i => $l) {
                $m = $moves[$l->id] ?? null;
                if (! $m) {
                    continue;
                }
                $product = $products[$l->product_id];
                if ($m->movement_type === 'adjustment_out') {
                    $after = $this->stock->stockIn($product, $warehouse, Quantity::of($m->quantity_out), Money::of($m->value_out), $adj, $date, 'reversal', $l->id);
                    if (! $after->trueUp->isZero()) {
                        $b->debit($warehouse->inventory_ledger_id, $after->trueUp, ['warehouse_id' => $warehouse->id])->credit($this->mapper->ledgerFor('cogs'), $after->trueUp);
                    }
                } else {
                    $orig = Money::of($m->value_in);
                    $out = $this->stock->reverseIn($product, $warehouse, Quantity::of($m->quantity_in), $orig, $adj, $date, $l->id, "lines.{$i}.counted_qty");
                    $diff = $orig->minus($out);
                    if (! $diff->isZero()) { // R-17: the stock gave up less value than the gain brought in
                        $b->debit($warehouse->inventory_ledger_id, $diff, ['warehouse_id' => $warehouse->id])->credit($this->mapper->ledgerFor('stock_adjustment'), $diff);
                    }
                }
            }
            $entry = null;
            if ($b->totalDebit()->isPositive()) {
                $entry = $this->posting->persist($b, $adj, 'reversal', $date, null, "Reversal of {$adj->number} ({$reason})", $adj->number, $warehouse->branch_id, $original?->id);
                $this->stock->linkJournal($adj, $entry->id);
            }
            $original?->forceFill(['status' => 'reversed'])->save();
            $adj->forceFill(['status' => 'reversed'])->save();
            $this->audit->record('reversed', $adj, ['status' => 'posted'], ['status' => 'reversed', 'reason' => $reason, 'reversal_entry_id' => $entry?->id]);

            return $adj->refresh()->load('lines.product.unit');
        }, attempts: 2);
    }

    /**
     * What posting would write now (draft) or wrote (posted): PostingLine rows. A draft is valued at today's average.
     *
     * @return list<array{ledger_id: int, ledger_code: string, ledger_name: string, debit: string, credit: string}>
     */
    public function postingPreview(StockAdjustment $adj): array
    {
        if ($adj->journal_entry_id) {
            $rows = JournalEntry::with('lines.ledger')->find($adj->journal_entry_id)?->lines
                ->map(fn ($l) => [$l->ledger, Money::of($l->debit), Money::of($l->credit)])->all() ?? [];
        } elseif ($adj->status === 'draft') {
            $warehouse = Warehouse::find($adj->warehouse_id);
            $short = Money::sum($adj->lines->filter(fn ($l) => $l->value->isNegative())->map(fn ($l) => $l->value->abs()));
            $gain = Money::sum($adj->lines->filter(fn ($l) => $l->value->isPositive())->map(fn ($l) => $l->value));
            $adjLedger = $this->mapper->ledgerFor('stock_adjustment');
            $inventory = Ledger::find($warehouse?->inventory_ledger_id);
            $rows = array_values(array_filter([
                $short->isPositive() ? [$adjLedger, $short, Money::zero()] : null, $gain->isPositive() ? [$inventory, $gain, Money::zero()] : null,
                $short->isPositive() ? [$inventory, Money::zero(), $short] : null, $gain->isPositive() ? [$adjLedger, Money::zero(), $gain] : null,
            ]));
        } else {
            return [];
        }

        return array_map(fn ($r) => ['ledger_id' => $r[0]->id, 'ledger_code' => $r[0]->code, 'ledger_name' => $r[0]->name,
            'debit' => (string) $r[1], 'credit' => (string) $r[2]], $rows);
    }

    private function assertDraft(StockAdjustment $adj): void
    {
        if ($adj->status !== 'draft') {
            throw new ApiProblem('not_draft', "{$adj->number} is {$adj->status}; only a draft can be changed, posted or deleted.", extra: ['status' => $adj->status]);
        }
    }

    /**
     * Checks the warehouse and products and reads, per line, the system quantity and average now.
     *
     * @return array{0: Warehouse, 1: list<array{product: Product, system: Quantity, counted: Quantity, diff: Quantity, avg: UnitCost, value: Money}>}
     */
    private function prepare(array $in): array
    {
        $warehouse = Warehouse::findOrFail($in['warehouse_id']);
        if ($warehouse->status === 'inactive') {
            throw new ApiProblem('inactive_reference', errors: ['warehouse_id' => ['This warehouse is inactive']]);
        }
        $products = Product::with('unit')->whereIn('id', array_column($in['lines'], 'product_id'))->get()->keyBy('id');
        $errors = [];
        $seen = [];
        foreach ($in['lines'] as $i => $l) {
            $p = $products[$l['product_id']] ?? null;
            if (! $p || ! $p->track_stock) {
                $errors["lines.{$i}.product_id"] = ['Choose a product that keeps stock'];
            } elseif (! $p->is_active) {
                $errors["lines.{$i}.product_id"] = ["{$p->name} is inactive"];
            } elseif (isset($seen[$p->id])) {
                $errors["lines.{$i}.product_id"] = ["{$p->name} is already on line ".($seen[$p->id] + 1)];
            }
            $seen[$l['product_id']] ??= $i;
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', errors: $errors);
        }
        $balances = StockBalance::where('warehouse_id', $warehouse->id)->whereIn('product_id', $products->keys())->get()->keyBy('product_id');

        return [$warehouse, array_map(function ($l) use ($products, $balances) {
            $b = $balances[$l['product_id']] ?? null;
            $system = Quantity::of($b->qty_on_hand ?? '0');
            $counted = Quantity::of($l['counted_qty']);
            $avg = UnitCost::of($b->avg_unit_cost ?? '0');
            $diff = $counted->minus($system);

            return ['product' => $products[$l['product_id']], 'system' => $system, 'counted' => $counted, 'diff' => $diff, 'avg' => $avg,
                'value' => $diff->isNegative() ? self::value($diff->abs(), $avg)->negated() : self::value($diff, $avg)];
        }, $in['lines'])];
    }

    private function fill(StockAdjustment $adj, Warehouse $warehouse, array $lines, array $in): void
    {
        $adj->fill(['adjustment_date' => $in['adjustment_date'], 'warehouse_id' => $warehouse->id, 'reason' => $in['reason'], 'notes' => $in['notes'] ?? null,
            'net_value' => Money::sum(array_map(fn ($l) => $l['value'], $lines))]);
    }

    private function saveLines(StockAdjustment $adj, array $lines): void
    {
        foreach ($lines as $i => $l) {
            $adj->lines()->create(['line_no' => $i + 1, 'product_id' => $l['product']->id, 'system_qty' => $l['system'], 'counted_qty' => $l['counted'],
                'difference_qty' => $l['diff'], 'unit_cost' => $l['avg'], 'value' => $l['value']]);
        }
    }

    /** quantity × average cost, rounded HALF_UP to paisa (BR-010). */
    private static function value(Quantity $qty, UnitCost $avg): Money
    {
        return Money::of($qty->toBigDecimal()->multipliedBy($avg->toBigDecimal())->toScale(2, RoundingMode::HalfUp));
    }

    private static function qty(Quantity $q): string
    {
        $s = (string) $q;

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
