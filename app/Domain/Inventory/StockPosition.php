<?php

namespace App\Domain\Inventory;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;

/**
 * A product's balance in one warehouse after a movement, and for a stock-in the negative-stock true-up (R-16):
 * positive = the inventory ledger must be debited and COGS credited by that amount; negative = the other way round.
 */
final readonly class StockPosition
{
    public Money $trueUp;

    public function __construct(public Quantity $qty, public Money $value, public UnitCost $avg, ?Money $trueUp = null)
    {
        $this->trueUp = $trueUp ?? Money::zero();
    }
}
