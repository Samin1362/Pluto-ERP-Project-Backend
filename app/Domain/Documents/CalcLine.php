<?php

namespace App\Domain\Documents;

use App\Domain\Shared\Quantity;
use App\Domain\Shared\Rate;
use App\Domain\Shared\UnitCost;

/**
 * One line given to DocumentCalculator: quantity × unit price, a line discount and an optional tax rule, all as
 * value objects. Percentages are percents ("2" = 2 %). `stocked` lines take the header discount (06 §8).
 */
final readonly class CalcLine
{
    public function __construct(
        public int $lineNo,
        public Quantity $quantity,
        public UnitCost $unitPrice,
        public Rate $discountPct,
        public ?int $taxRuleId = null,
        public ?Rate $taxRatePct = null,
        public bool $stocked = true,
    ) {}
}
