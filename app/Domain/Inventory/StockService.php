<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The only writer of stock_balances and stock_movements (06 §4, §11; BR-014, BR-016, BR-017; R-16, R-17; backlog
 * P4.1-BE-06). Runs inside the posting transaction: balance rows are locked FOR UPDATE (created when missing) in
 * product_id, warehouse_id ascending order — call lock() with every pair of a multi-line document first, so two
 * documents never wait on each other in opposite orders. Each movement row is append-only and carries the quantity
 * and average cost after it; costs come from AverageCostCalculator.
 */
final class StockService
{
    public function __construct(private readonly AverageCostCalculator $calc, private readonly CurrentCompany $current) {}

    /**
     * Locks (and creates when missing) the balance rows of these product/warehouse pairs in a fixed order.
     *
     * @param  list<array{0: int, 1: int}>  $pairs  [product_id, warehouse_id]
     * @return array<string, StockBalance> keyed "product_id:warehouse_id"
     */
    public function lock(array $pairs): array
    {
        $pairs = array_values(array_unique(array_map(fn ($p) => $p[0].':'.$p[1], $pairs)));
        usort($pairs, fn ($a, $b) => array_map('intval', explode(':', $a)) <=> array_map('intval', explode(':', $b)));
        $out = [];
        foreach ($pairs as $key) {
            [$productId, $warehouseId] = array_map('intval', explode(':', $key));
            DB::table('stock_balances')->insertOrIgnore(['company_id' => $this->current->id(), 'product_id' => $productId, 'warehouse_id' => $warehouseId,
                'created_at' => now(), 'updated_at' => now()]);
            $out[$key] = StockBalance::where('product_id', $productId)->where('warehouse_id', $warehouseId)->lockForUpdate()->firstOrFail();
        }

        return $out;
    }

    /**
     * Receives stock at a value (purchase_in, opening, adjustment_in, sales_return_in, transfer_in …). The returned
     * position's trueUp is the negative-stock COGS true-up the posting service must journal (R-16; zero normally).
     */
    public function stockIn(Product $product, Warehouse $warehouse, Quantity $qty, Money $value, Model $source, CarbonInterface|string $date,
        string $movementType = 'purchase_in', ?int $sourceLineId = null): StockPosition
    {
        $this->assertTracked($product);
        $balance = $this->lock([[$product->id, $warehouse->id]])["{$product->id}:{$warehouse->id}"];
        $after = $this->calc->afterIn(Quantity::of($balance->qty_on_hand), Money::of($balance->total_value), UnitCost::of($balance->avg_unit_cost), $qty, $value);
        $this->write($balance, $after, $movementType, $date, $source, $sourceLineId,
            in: $qty, out: Quantity::zero(), unitCost: UnitCost::of($value->dividedBy($qty, UnitCost::SCALE)), valueIn: $value->plus($after->trueUp), valueOut: Money::zero());

        return $after;
    }

    /**
     * Issues stock at the current average (sale_out, adjustment_out, purchase_return_out, transfer_out …) and returns
     * the cost of the issue (BR-016). Refuses with insufficient_stock when the warehouse holds less, unless negative
     * stock is allowed for the company (BR-014) or `$allowNegative` says so.
     */
    public function stockOut(Product $product, Warehouse $warehouse, Quantity $qty, Model $source, CarbonInterface|string $date,
        string $movementType = 'sale_out', ?int $sourceLineId = null, ?bool $allowNegative = null, string $errorKey = 'quantity'): Money
    {
        $this->assertTracked($product);
        $balance = $this->lock([[$product->id, $warehouse->id]])["{$product->id}:{$warehouse->id}"];
        $onHand = Quantity::of($balance->qty_on_hand);
        if ($onHand->isLessThan($qty) && ! ($allowNegative ?? $this->negativeAllowed())) {
            throw new ApiProblem('insufficient_stock', "Only {$onHand} {$product->unit?->name} of {$product->name} in {$warehouse->name}; {$qty} needed.",
                errors: [$errorKey => ["Only {$onHand} on hand in {$warehouse->name}"]], extra: ['shortages' => [$this->shortage($product, $warehouse, $onHand, $qty)]]);
        }
        $avg = UnitCost::of($balance->avg_unit_cost);
        [$after, $cost] = $this->calc->afterOut($onHand, Money::of($balance->total_value), $avg, $qty);
        $this->write($balance, $after, $movementType, $date, $source, $sourceLineId,
            in: Quantity::zero(), out: $qty, unitCost: $avg, valueIn: Money::zero(), valueOut: $cost);

        return $cost;
    }

    /**
     * Takes back a receipt (cancelled bill, 06 §15) at its original value, as a `reversal` movement. Refuses with
     * insufficient_stock when the warehouse no longer holds the quantity, unless negative stock is allowed (BR-014,
     * TC-PUR-010). Returns the value actually taken out (R-17 may lower it; the caller posts the difference). A purchase
     * return (06 §10) uses it with purchase_return_out: the goods leave at the cost they came in at.
     */
    public function reverseIn(Product $product, Warehouse $warehouse, Quantity $qty, Money $origValue, Model $source, CarbonInterface|string $date,
        ?int $sourceLineId = null, string $errorKey = 'quantity', string $movementType = 'reversal'): Money
    {
        $this->assertTracked($product);
        $balance = $this->lock([[$product->id, $warehouse->id]])["{$product->id}:{$warehouse->id}"];
        $onHand = Quantity::of($balance->qty_on_hand);
        if ($onHand->isLessThan($qty) && ! $this->negativeAllowed()) {
            throw new ApiProblem('insufficient_stock', "Only {$onHand} {$product->unit?->name} of {$product->name} left in {$warehouse->name}; {$qty} would go back to the supplier.",
                errors: [$errorKey => ["Only {$onHand} on hand in {$warehouse->name}"]], extra: ['shortages' => [$this->shortage($product, $warehouse, $onHand, $qty)]]);
        }
        [$after, $out] = $this->calc->afterReverseIn($onHand, Money::of($balance->total_value), UnitCost::of($balance->avg_unit_cost), $qty, $origValue);
        $this->write($balance, $after, $movementType, $date, $source, $sourceLineId,
            in: Quantity::zero(), out: $qty, unitCost: UnitCost::of($out->dividedBy($qty, UnitCost::SCALE)), valueIn: Money::zero(), valueOut: $out);

        return $out;
    }

    /**
     * Issues several lines at once (an invoice): locks every pair in order, checks every line first so the user sees
     * all shortages together (BR-014, per-line detail keyed lines.N.quantity), then issues each.
     *
     * @param  array<int, array{product: Product, warehouse: Warehouse, qty: Quantity, source_line_id?: int}>  $lines  keyed by the API line index
     * @return array<int, Money> cost per line index
     */
    public function stockOutMany(array $lines, Model $source, CarbonInterface|string $date, string $movementType = 'sale_out', ?bool $allowNegative = null): array
    {
        $balances = $this->lock(array_values(array_map(fn ($l) => [$l['product']->id, $l['warehouse']->id], $lines)));
        if (! ($allowNegative ?? $this->negativeAllowed())) {
            $need = [];
            foreach ($lines as $i => $l) {
                $key = "{$l['product']->id}:{$l['warehouse']->id}";
                $need[$key] = ($need[$key] ?? Quantity::zero())->plus($l['qty']);
                $onHand = Quantity::of($balances[$key]->qty_on_hand);
                if ($onHand->isLessThan($need[$key])) {
                    $errors["lines.{$i}.quantity"] = ["Only {$onHand} on hand in {$l['warehouse']->name}"];
                    $shortages[] = $this->shortage($l['product'], $l['warehouse'], $onHand, $need[$key]) + ['line' => $i];
                }
            }
            if (! empty($errors)) {
                throw new ApiProblem('insufficient_stock', count($errors) === 1 ? 'One line needs more stock than the warehouse holds.'
                    : count($errors).' lines need more stock than the warehouses hold.', errors: $errors, extra: ['shortages' => $shortages]);
            }
        }
        $costs = [];
        foreach ($lines as $i => $l) {
            $costs[$i] = $this->stockOut($l['product'], $l['warehouse'], $l['qty'], $source, $date, $movementType, $l['source_line_id'] ?? null, true);
        }

        return $costs;
    }

    /** After PostingService wrote the entry: the document's movements point at it (the only update a movement gets). */
    public function linkJournal(Model $source, int $journalEntryId): void
    {
        StockMovement::where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->whereNull('journal_entry_id')
            ->update(['journal_entry_id' => $journalEntryId]);
    }

    private function write(StockBalance $balance, StockPosition $after, string $type, CarbonInterface|string $date, Model $source, ?int $lineId,
        Quantity $in, Quantity $out, UnitCost $unitCost, Money $valueIn, Money $valueOut): void
    {
        StockMovement::create([
            'product_id' => $balance->product_id, 'warehouse_id' => $balance->warehouse_id, 'movement_type' => $type,
            'moved_on' => $date instanceof CarbonInterface ? $date->toDateString() : $date,
            'quantity_in' => $in, 'quantity_out' => $out, 'unit_cost' => $unitCost, 'value_in' => $valueIn, 'value_out' => $valueOut,
            'qty_balance_after' => $after->qty, 'avg_cost_after' => $after->avg,
            'source_type' => $source->getMorphClass(), 'source_id' => $source->getKey(), 'source_line_id' => $lineId,
        ]);
        $balance->forceFill(['qty_on_hand' => (string) $after->qty, 'total_value' => (string) $after->value, 'avg_unit_cost' => (string) $after->avg])->save();
    }

    private function assertTracked(Product $product): void
    {
        if (! $product->track_stock) {
            throw new LogicException("{$product->code} does not track stock (a service, CF-09); it has no stock movements.");
        }
    }

    private function negativeAllowed(): bool
    {
        return (bool) CompanySetting::value('allow_negative_stock');
    }

    private function shortage(Product $p, Warehouse $w, Quantity $onHand, Quantity $needed): array
    {
        return ['product_id' => $p->id, 'product_code' => $p->code, 'warehouse_id' => $w->id, 'available' => (string) $onHand, 'requested' => (string) $needed];
    }
}
