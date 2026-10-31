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
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stock transfers (D11; 06 §11.4; BR-030, BR-014, BR-009, BR-017; TC-INV-004; backlog P11.1-BE-03).
 *
 * Draft → dispatched → received, or cancelled. A draft needs two different warehouses and, per product, no more than
 * the source holds. Dispatch issues transfer_out at the source average (the value is frozen with the transfer) and
 * counts the quantity as in transit from the source. Receive brings transfer_in into the destination at that frozen
 * value, averaged in there (BR-017); what was dispatched but not received is a transit loss, posted like an adjustment
 * shortage (Dr stock adjustment). Entries: when the two warehouses use different inventory ledgers, dispatch posts
 * Dr stock in transit / Cr source inventory and receive Dr destination inventory (+ loss) / Cr stock in transit; with
 * the same ledger only a transit loss posts (Dr stock adjustment / Cr inventory). Total stock value never changes
 * except by the loss. A dispatched transfer can be cancelled (stock and entry come back); a received one cannot.
 */
final class StockTransferService
{
    public function __construct(
        private readonly PeriodGuard $periods,
        private readonly DocumentNumberService $numbers,
        private readonly StockService $stock,
        private readonly PostingService $posting,
        private readonly AccountMapper $mapper,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $in): StockTransfer
    {
        return DB::transaction(function () use ($in) {
            [$from, $to] = $this->prepare($in);
            $period = $this->periods->assertOpen($in['dispatch_date'] ?? $this->today());
            $t = new StockTransfer(['status' => 'draft']);
            $t->number = $this->numbers->next('stock_transfer', $period->fiscal_year_id)['number'];
            $this->fill($t, $in);
            $t->save();
            $this->saveLines($t, $in['lines']);
            $this->audit->record('created', $t, null, ['number' => $t->number, 'from' => $from->name, 'to' => $to->name]);

            return $t->refresh()->load('lines.product.unit');
        });
    }

    public function update(StockTransfer $t, array $in, int $expectedVersion): StockTransfer
    {
        return DB::transaction(function () use ($t, $in, $expectedVersion) {
            $t = StockTransfer::whereKey($t->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($t);
            if ((int) $t->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $t->lock_version]);
            }
            $this->prepare($in);
            $old = ['from_warehouse_id' => $t->from_warehouse_id, 'to_warehouse_id' => $t->to_warehouse_id];
            $this->fill($t, $in);
            $t->lines()->delete();
            $t->save();
            $this->saveLines($t, $in['lines']);
            $this->audit->record('updated', $t, $old, ['from_warehouse_id' => $t->from_warehouse_id, 'to_warehouse_id' => $t->to_warehouse_id]);

            return $t->load('lines.product.unit');
        });
    }

    public function delete(StockTransfer $t): void
    {
        DB::transaction(function () use ($t) {
            $t = StockTransfer::whereKey($t->id)->lockForUpdate()->firstOrFail();
            $this->assertDraft($t);
            $t->lines()->delete();
            $t->delete();
            $this->audit->record('voided_number', $t, ['number' => $t->number], ['reason' => 'draft deleted']);
            $this->audit->record('deleted', $t, ['number' => $t->number]);
        });
    }

    public function dispatch(StockTransfer $t): StockTransfer
    {
        return DB::transaction(function () use ($t) {
            $t = StockTransfer::with('lines')->whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status !== 'draft') {
                throw new ApiProblem('already_posted', "{$t->number} is already {$t->status}.");
            }
            [$from, $to] = $this->warehouses($t->from_warehouse_id, $t->to_warehouse_id);
            $date = $t->dispatch_date?->toDateString() ?? $this->today();
            $this->periods->assertOpen($date);
            $products = Product::withTrashed()->whereIn('id', $t->lines->pluck('product_id'))->get()->keyBy('id');
            $this->stock->lock($t->lines->flatMap(fn ($l) => [[$l->product_id, $from->id], [$l->product_id, $to->id]])->all());
            $costs = $this->stock->stockOutMany($t->lines->values()->map(fn ($l) => ['product' => $products[$l->product_id], 'warehouse' => $from,
                'qty' => Quantity::of($l->quantity), 'source_line_id' => $l->id])->all(), $t, $date, 'transfer_out');
            $total = Money::zero();
            foreach ($t->lines->values() as $i => $l) {
                $l->forceFill(['unit_cost' => UnitCost::of($costs[$i]->dividedBy(Quantity::of($l->quantity), UnitCost::SCALE))])->save();
                $this->inTransit($l->product_id, $from->id, Quantity::of($l->quantity));
                $total = $total->plus($costs[$i]);
            }
            $entry = null;
            if ($from->inventory_ledger_id !== $to->inventory_ledger_id && $total->isPositive()) {
                $b = (new JournalBuilder)->debit($this->mapper->ledgerFor('stock_in_transit'), $total)
                    ->credit($from->inventory_ledger_id, $total, ['warehouse_id' => $from->id]);
                $entry = $this->posting->persist($b, $t, 'stock_transfer', $date, $t->number, "Stock sent from {$from->name} to {$to->name}", null, $from->branch_id);
                $this->stock->linkJournal($t, $entry->id);
            }
            $t->forceFill(['status' => 'dispatched', 'dispatch_date' => $date, 'dispatch_entry_id' => $entry?->id])->save();
            $this->audit->record('posted', $t, ['status' => 'draft'], ['status' => 'dispatched', 'value' => (string) $total, 'journal_entry_id' => $entry?->id]);

            return $t->refresh()->load('lines.product.unit');
        }, attempts: 2);
    }

    /** @param  list<array{product_id: int, quantity: string}>|null  $received  default: everything dispatched arrived */
    public function receive(StockTransfer $t, string $date, ?array $received): StockTransfer
    {
        return DB::transaction(function () use ($t, $date, $received) {
            $t = StockTransfer::with('lines')->whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status === 'received') {
                throw new ApiProblem('already_posted', "{$t->number} is already received.");
            }
            if ($t->status !== 'dispatched') {
                throw new ApiProblem('invalid_transition', "{$t->number} is {$t->status}; dispatch it before receiving it.", extra: ['status' => $t->status]);
            }
            if ($date < $t->dispatch_date->toDateString()) {
                throw new ApiProblem('validation_failed', errors: ['received_date' => ['Goods cannot arrive before they were sent ('.$t->dispatch_date->format('j M Y').')']]);
            }
            $this->periods->assertOpen($date);
            [$from, $to] = $this->warehouses($t->from_warehouse_id, $t->to_warehouse_id, receiving: true);
            $qty = $this->receivedQuantities($t, $received);
            $products = Product::withTrashed()->whereIn('id', $t->lines->pluck('product_id'))->get()->keyBy('id');
            $this->stock->lock($t->lines->flatMap(fn ($l) => [[$l->product_id, $from->id], [$l->product_id, $to->id]])->all());
            $out = StockMovement::where('source_type', $t->getMorphClass())->where('source_id', $t->id)->where('movement_type', 'transfer_out')->get()->keyBy('source_line_id');

            $sent = Money::zero();
            $arrived = Money::zero();
            $trueUp = Money::zero();
            foreach ($t->lines as $l) {
                $value = Money::of($out[$l->id]->value_out);
                $got = $qty[$l->id];
                $in = $got->isEqualTo(Quantity::of($l->quantity)) ? $value
                    : Money::of($value->toBigDecimal()->multipliedBy($got->toBigDecimal())->dividedBy(Quantity::of($l->quantity)->toBigDecimal(), 2, RoundingMode::HalfUp));
                if ($got->isPositive()) {
                    $after = $this->stock->stockIn($products[$l->product_id], $to, $got, $in, $t, $date, 'transfer_in', $l->id);
                    $trueUp = $trueUp->plus($after->trueUp);
                }
                $this->inTransit($l->product_id, $from->id, Quantity::of($l->quantity)->negated());
                $l->forceFill(['received_quantity' => $got])->save();
                $sent = $sent->plus($value);
                $arrived = $arrived->plus($in);
            }
            $loss = $sent->minus($arrived);
            $b = new JournalBuilder;
            if ($from->inventory_ledger_id !== $to->inventory_ledger_id) {
                $b->debit($to->inventory_ledger_id, $arrived, ['warehouse_id' => $to->id])->debit($this->mapper->ledgerFor('stock_adjustment'), $loss)
                    ->credit($this->mapper->ledgerFor('stock_in_transit'), $sent);
            } elseif ($loss->isPositive()) { // same ledger: the goods never left it in the books until now
                $b->debit($this->mapper->ledgerFor('stock_adjustment'), $loss)->credit($to->inventory_ledger_id, $loss, ['warehouse_id' => $to->id]);
            }
            if ($trueUp->isPositive()) { // R-16: the arrival covered negative stock at the destination
                $b->debit($to->inventory_ledger_id, $trueUp, ['warehouse_id' => $to->id])->credit($this->mapper->ledgerFor('cogs'), $trueUp);
            }
            $entry = null;
            if ($b->totalDebit()->isPositive()) {
                $entry = $this->posting->persist($b, $t, 'stock_transfer', $date, null,
                    "Stock received at {$to->name} from {$from->name}".($loss->isPositive() ? " (transit loss {$loss})" : ''), $t->number, $to->branch_id);
                StockMovement::where('source_type', $t->getMorphClass())->where('source_id', $t->id)->where('movement_type', 'transfer_in')->whereNull('journal_entry_id')
                    ->update(['journal_entry_id' => $entry->id]);
            }
            $t->forceFill(['status' => 'received', 'received_date' => $date, 'receive_entry_id' => $entry?->id])->save();
            $this->audit->record('updated', $t, ['status' => 'dispatched'], ['status' => 'received', 'transit_loss' => (string) $loss, 'journal_entry_id' => $entry?->id]);

            return $t->refresh()->load('lines.product.unit');
        }, attempts: 2);
    }

    /** A draft is cancelled as it is; a dispatched transfer brings its stock back to the source and mirrors its entry. */
    public function cancel(StockTransfer $t, string $reason): StockTransfer
    {
        return DB::transaction(function () use ($t, $reason) {
            $t = StockTransfer::with('lines')->whereKey($t->id)->lockForUpdate()->firstOrFail();
            if ($t->status === 'cancelled') {
                throw new ApiProblem('already_reversed', "{$t->number} is already cancelled.");
            }
            if ($t->status === 'received') {
                throw new ApiProblem('invalid_transition', "{$t->number} is received; move the stock back with a new transfer instead.", extra: ['status' => $t->status]);
            }
            $old = $t->status;
            $entry = null;
            if ($t->status === 'dispatched') {
                $date = $this->today();
                $this->periods->assertOpen($date);
                $from = Warehouse::findOrFail($t->from_warehouse_id);
                $products = Product::withTrashed()->whereIn('id', $t->lines->pluck('product_id'))->get()->keyBy('id');
                $this->stock->lock($t->lines->map(fn ($l) => [$l->product_id, $from->id])->all());
                $out = StockMovement::where('source_type', $t->getMorphClass())->where('source_id', $t->id)->where('movement_type', 'transfer_out')->get()->keyBy('source_line_id');
                $b = new JournalBuilder;
                foreach ($t->lines as $l) {
                    $after = $this->stock->stockIn($products[$l->product_id], $from, Quantity::of($l->quantity), Money::of($out[$l->id]->value_out), $t, $date, 'reversal', $l->id);
                    $this->inTransit($l->product_id, $from->id, Quantity::of($l->quantity)->negated());
                    if ($after->trueUp->isPositive()) {
                        $b->debit($from->inventory_ledger_id, $after->trueUp, ['warehouse_id' => $from->id])->credit($this->mapper->ledgerFor('cogs'), $after->trueUp);
                    }
                }
                $original = $t->dispatch_entry_id ? JournalEntry::with('lines')->whereKey($t->dispatch_entry_id)->lockForUpdate()->firstOrFail() : null;
                foreach ($original?->lines ?? [] as $l) {
                    $meta = array_filter(['warehouse_id' => $l->warehouse_id]);
                    $b->debit($l->ledger_id, $l->credit, $meta)->credit($l->ledger_id, $l->debit, $meta);
                }
                if ($b->totalDebit()->isPositive()) {
                    $entry = $this->posting->persist($b, $t, 'cancellation', $date, null, "Cancellation of {$t->number} ({$reason})", $t->number, $from->branch_id, $original?->id);
                    StockMovement::where('source_type', $t->getMorphClass())->where('source_id', $t->id)->where('movement_type', 'reversal')->whereNull('journal_entry_id')
                        ->update(['journal_entry_id' => $entry->id]);
                }
                $original?->forceFill(['status' => 'reversed'])->save();
            }
            $t->forceFill(['status' => 'cancelled'])->save();
            $this->audit->record('cancelled', $t, ['status' => $old], ['status' => 'cancelled', 'reason' => $reason, 'cancellation_entry_id' => $entry?->id]);

            return $t->refresh()->load('lines.product.unit');
        }, attempts: 2);
    }

    /** Value of a transfer at cost: what left the source once dispatched, else the quantity at today's source average. */
    public function valueAtCost(StockTransfer $t): Money
    {
        if ($t->status !== 'draft') {
            return Money::of((string) StockMovement::where('source_type', $t->getMorphClass())->where('source_id', $t->id)->where('movement_type', 'transfer_out')->sum('value_out'));
        }
        $avg = StockBalance::where('warehouse_id', $t->from_warehouse_id)->whereIn('product_id', $t->lines->pluck('product_id'))->pluck('avg_unit_cost', 'product_id');

        return Money::sum($t->lines->map(fn ($l) => Money::of(Quantity::of($l->quantity)->toBigDecimal()->multipliedBy((string) ($avg[$l->product_id] ?? '0'))->toScale(2, RoundingMode::HalfUp))));
    }

    /** The loss on receipt: value dispatched minus value received (0 until received). */
    public function transitLoss(StockTransfer $t): Money
    {
        if ($t->status !== 'received') {
            return Money::zero();
        }
        $m = StockMovement::where('source_type', $t->getMorphClass())->where('source_id', $t->id)->selectRaw(
            "COALESCE(SUM(CASE WHEN movement_type = 'transfer_out' THEN value_out ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN movement_type = 'transfer_in' THEN value_in ELSE 0 END), 0) as loss")->value('loss');

        return Money::of((string) $m);
    }

    private function assertDraft(StockTransfer $t): void
    {
        if ($t->status !== 'draft') {
            throw new ApiProblem('not_draft', "{$t->number} is {$t->status}; only a draft can be changed or deleted.", extra: ['status' => $t->status]);
        }
    }

    /** @return array{0: Warehouse, 1: Warehouse} */
    private function warehouses(int $fromId, int $toId, bool $receiving = false): array
    {
        if ($fromId === $toId) {
            throw new ApiProblem('same_warehouse', 'Choose two different warehouses.', errors: ['to_warehouse_id' => ['Choose a warehouse other than the source']]);
        }
        $from = Warehouse::findOrFail($fromId);
        $to = Warehouse::findOrFail($toId);
        foreach (['from_warehouse_id' => $from, 'to_warehouse_id' => $to] as $field => $w) {
            if ($w->status === 'inactive' && ! ($receiving && $field === 'from_warehouse_id')) {
                throw new ApiProblem('inactive_reference', errors: [$field => ["{$w->name} is inactive"]]);
            }
        }

        return [$from, $to];
    }

    /** Draft checks (BR-030): two warehouses, stock-tracked active products once each, quantity ≤ available at the source. */
    private function prepare(array $in): array
    {
        [$from, $to] = $this->warehouses((int) $in['from_warehouse_id'], (int) $in['to_warehouse_id']);
        $products = Product::whereIn('id', array_column($in['lines'], 'product_id'))->get()->keyBy('id');
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
        $onHand = StockBalance::where('warehouse_id', $from->id)->whereIn('product_id', $products->keys())->pluck('qty_on_hand', 'product_id');
        $short = [];
        foreach ($in['lines'] as $i => $l) {
            $have = Quantity::of((string) ($onHand[$l['product_id']] ?? '0'));
            if ($have->isLessThan(Quantity::of($l['quantity']))) {
                $short["lines.{$i}.quantity"] = ['Only '.self::qty($have)." available in {$from->name}"];
            }
        }
        if ($short) {
            throw new ApiProblem('insufficient_stock', count($short) === 1 ? "One line needs more than {$from->name} holds." : count($short)." lines need more than {$from->name} holds.", errors: $short);
        }

        return [$from, $to];
    }

    /** @return array<int, Quantity> received quantity per transfer line id */
    private function receivedQuantities(StockTransfer $t, ?array $received): array
    {
        $out = [];
        foreach ($t->lines as $l) {
            $out[$l->id] = Quantity::of($l->quantity);
        }
        if ($received === null) {
            return $out;
        }
        $byProduct = $t->lines->keyBy('product_id');
        $errors = [];
        foreach ($received as $i => $r) {
            $l = $byProduct[$r['product_id']] ?? null;
            if (! $l) {
                $errors["lines.{$i}.product_id"] = ['This product is not on the transfer'];

                continue;
            }
            $q = Quantity::of($r['quantity']);
            if ($q->isGreaterThan(Quantity::of($l->quantity))) {
                $errors["lines.{$i}.quantity"] = ['At most '.self::qty(Quantity::of($l->quantity)).' were sent'];

                continue;
            }
            $out[$l->id] = $q;
        }
        if ($errors) {
            throw new ApiProblem('validation_failed', errors: $errors);
        }

        return $out;
    }

    private function inTransit(int $productId, int $warehouseId, Quantity $delta): void
    {
        $b = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->lockForUpdate()->firstOrFail();
        $next = Quantity::of($b->qty_in_transit_out)->plus($delta);
        $b->forceFill(['qty_in_transit_out' => (string) ($next->isNegative() ? Quantity::zero() : $next)])->save();
    }

    private function fill(StockTransfer $t, array $in): void
    {
        $t->fill(['from_warehouse_id' => $in['from_warehouse_id'], 'to_warehouse_id' => $in['to_warehouse_id'], 'dispatch_date' => $in['dispatch_date'] ?? null,
            'vehicle' => $in['vehicle'] ?? null, 'driver' => $in['driver'] ?? null, 'notes' => $in['notes'] ?? null]);
    }

    private function saveLines(StockTransfer $t, array $lines): void
    {
        foreach (array_values($lines) as $i => $l) {
            $t->lines()->create(['line_no' => $i + 1, 'product_id' => $l['product_id'], 'quantity' => $l['quantity']]);
        }
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private static function qty(Quantity $q): string
    {
        $s = (string) $q;

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
