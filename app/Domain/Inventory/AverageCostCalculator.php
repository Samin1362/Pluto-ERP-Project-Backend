<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use InvalidArgumentException;

/**
 * Moving weighted average per product and warehouse (06 §11.1; BR-016, BR-017; review R-16, R-17; backlog P4.1-BE-06).
 * Pure: the caller passes the balance (quantity, value, average) and gets the balance after the movement.
 *
 * Stock in (BR-017): with stock on hand, average = (value + incoming value) ÷ (quantity + incoming quantity). With
 * none or less, the incoming unit cost becomes the average. When the quantity was negative (negative stock allowed),
 * the incoming quantity first covers the shortfall; the shortfall had been issued at the old average, so the
 * difference to the incoming cost is a true-up for COGS (R-16), and value and quantity reach zero together.
 *
 * Stock out (BR-016): cost = quantity × average. Issuing the last units takes exactly the remaining value (no
 * residue); value never goes below zero while quantity stays positive (R-17). Below zero (allowed negative stock)
 * the remaining average is the last one (R-16).
 */
final class AverageCostCalculator
{
    public function afterIn(Quantity $qty, Money $value, UnitCost $avg, Quantity $inQty, Money $inValue): StockPosition
    {
        if (! $inQty->isPositive() || $inValue->isNegative()) {
            throw new InvalidArgumentException('A stock-in needs a quantity > 0 and a value ≥ 0.');
        }
        $newQty = $qty->plus($inQty);
        $incoming = UnitCost::of($inValue->dividedBy($inQty, UnitCost::SCALE));
        if ($qty->isPositive()) {
            $newValue = $value->plus($inValue);

            return new StockPosition($newQty, $newValue, UnitCost::of($newValue->dividedBy($newQty, UnitCost::SCALE)), Money::zero());
        }
        if ($qty->isZero()) {
            // Nothing on hand: the receipt is the whole value; any residue left at zero quantity is cleared to COGS.
            return new StockPosition($newQty, $inValue, $incoming, $value->negated());
        }
        // Negative stock (R-16): units still short stay at the old average; covered units are re-costed at the incoming cost.
        $newValue = $newQty->isPositive() ? Money::of($newQty->toBigDecimal()->multipliedBy($incoming->toBigDecimal()))
            : Money::of($newQty->toBigDecimal()->multipliedBy($avg->toBigDecimal()));
        if ($newQty->isZero()) {
            $newValue = Money::zero();
        }

        return new StockPosition($newQty, $newValue, $newQty->isPositive() ? $incoming : $avg, $newValue->minus($value->plus($inValue)));
    }

    /**
     * Takes back a receipt at its original value (cancelled bill, 06 §15; R-17). The stock leaves at `origValue`
     * unless that would leave value below zero while quantity stays positive (then the remaining value) or leave value
     * behind at zero quantity (then exactly the remaining value). Below zero (allowed negative) the original value.
     *
     * @return array{0: StockPosition, 1: Money} the balance after and the value actually taken out of stock
     */
    public function afterReverseIn(Quantity $qty, Money $value, UnitCost $avg, Quantity $outQty, Money $origValue): array
    {
        if (! $outQty->isPositive()) {
            throw new InvalidArgumentException('A reversal needs a quantity > 0.');
        }
        $newQty = $qty->minus($outQty);
        $out = match (true) {
            $newQty->isZero() => $value,
            $newQty->isPositive() && $origValue->isGreaterThan($value) => $value->isNegative() ? Money::zero() : $value,
            default => $origValue,
        };
        $newValue = $value->minus($out);
        $newAvg = $newQty->isPositive() ? UnitCost::of($newValue->dividedBy($newQty, UnitCost::SCALE)) : $avg;

        return [new StockPosition($newQty, $newValue, $newAvg), $out];
    }

    /** @return array{0: StockPosition, 1: Money} the balance after the issue and the cost of the issued quantity */
    public function afterOut(Quantity $qty, Money $value, UnitCost $avg, Quantity $outQty): array
    {
        if (! $outQty->isPositive()) {
            throw new InvalidArgumentException('A stock-out needs a quantity > 0.');
        }
        $newQty = $qty->minus($outQty);
        $cost = Money::of($outQty->toBigDecimal()->multipliedBy($avg->toBigDecimal()));
        if ($qty->isPositive() && ! $newQty->isPositive()) {
            // The last units on hand carry exactly the remaining value; anything beyond (allowed negative) at the average.
            $cost = $value->plus(Money::of($newQty->negated()->toBigDecimal()->multipliedBy($avg->toBigDecimal())));
        } elseif ($newQty->isPositive() && $cost->isGreaterThan($value)) {
            $cost = $value->isNegative() ? Money::zero() : $value; // R-17: value never below zero while quantity is positive
        }
        $newValue = $value->minus($cost);

        return [new StockPosition($newQty, $newValue, $avg), $cost];
    }
}
