<?php

namespace App\Domain\Documents;

use App\Domain\Shared\Money;

/**
 * A calculated line (BR-011): gross, line discount, its share of the header discount, net, tax and total, plus the
 * base-currency amounts (each rounded per line, 06 §8); for a base-currency document base = document amounts.
 */
final readonly class CalculatedLine
{
    public function __construct(
        public int $lineNo,
        public Money $gross,
        public Money $discount,
        public Money $headerDiscount,
        public Money $net,
        public Money $tax,
        public Money $total,
        public Money $baseNet,
        public Money $baseTax,
        public Money $baseTotal,
        public ?int $taxRuleId,
    ) {}

    /** Line discount plus its share of the header discount. */
    public function discountTotal(): Money
    {
        return $this->discount->plus($this->headerDiscount);
    }
}
