<?php

namespace App\Domain\Purchases;

use App\Domain\Shared\Quantity;
use App\Models\LetterOfCredit;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Support\Collection;

/**
 * How much a bill may still receive against its purchase order (BR-056). Bill lines linked to an order line may
 * together take at most what the order line still expects: ordered − received (posted bills only), in the order
 * line's unit. An import bill whose LC is raised against that same order may also use the LC's tolerance:
 * ordered × (1 + tolerance_pct / 100) − received. Unlinked lines are not limited.
 *
 * Used by calculate (warnings), by draft save (422 order_qty_exceeded) and again at posting, under the order lock,
 * so two drafts that each fit cannot both post past the order.
 */
final class PurchaseOrderLimits
{
    /**
     * @param  iterable<int, array{purchase_order_line_id?: int|string|null, quantity: string}>  $lines  keyed by API index
     * @param  Collection<int, PurchaseOrderLine>|null  $orderLines  already locked lines (posting); loaded when null
     * @return array<string, list<string>> field errors keyed lines.{i}.quantity
     */
    public function excess(?int $purchaseOrderId, ?int $letterOfCreditId, iterable $lines, ?Collection $orderLines = null): array
    {
        if (! $purchaseOrderId) {
            return [];
        }
        $po = PurchaseOrder::find($purchaseOrderId);
        if (! $po) {
            return [];
        }
        $orderLines ??= PurchaseOrderLine::where('purchase_order_id', $po->id)->get()->keyBy('id');
        $tolerance = $this->tolerance($po->id, $letterOfCreditId);

        $wanted = [];   // order line id => total quantity on this bill
        $indexes = [];  // order line id => bill line indexes
        foreach ($lines as $i => $l) {
            $olId = (int) ($l['purchase_order_line_id'] ?? 0);
            if (! $olId || ! isset($orderLines[$olId])) {
                continue;
            }
            $wanted[$olId] = ($wanted[$olId] ?? Quantity::zero())->plus(Quantity::of($l['quantity']));
            $indexes[$olId][] = $i;
        }

        $errors = [];
        foreach ($wanted as $olId => $qty) {
            $ol = $orderLines[$olId];
            $ordered = Quantity::of($ol->quantity);
            $limit = $tolerance->isZero() ? $ordered : $ordered->plus($ordered->multipliedBy($tolerance->toBigDecimal()->dividedBy(100, 6)));
            $left = $limit->minus(Quantity::of($ol->received_quantity));
            if ($left->isNegative()) {
                $left = Quantity::zero();
            }
            if (! $qty->isGreaterThan($left)) {
                continue;
            }
            $unit = $ol->unit_name ? " {$ol->unit_name}" : '';
            $with = $tolerance->isZero() ? '' : " (incl. the LC's {$tolerance->toBigDecimal()->stripTrailingZeros()}% tolerance)";
            $message = $left->isZero()
                ? "Everything on {$po->number} for {$ol->product_name} has already been received{$with}"
                : "Only {$left}{$unit} of {$ol->product_name} is left on {$po->number}{$with}; this bill has {$qty}{$unit}";
            foreach ($indexes[$olId] as $i) {
                $errors["lines.{$i}.quantity"] = [$message];
            }
        }

        return $errors;
    }

    /** The LC's tolerance applies only when the bill's LC is raised against this same order. */
    private function tolerance(int $purchaseOrderId, ?int $letterOfCreditId): Quantity
    {
        if (! $letterOfCreditId) {
            return Quantity::zero();
        }
        $lc = LetterOfCredit::find($letterOfCreditId);
        if (! $lc || (int) $lc->purchase_order_id !== $purchaseOrderId || ! $lc->tolerance_pct || (float) $lc->tolerance_pct <= 0) {
            return Quantity::zero();
        }

        return Quantity::of((string) $lc->tolerance_pct);
    }
}
