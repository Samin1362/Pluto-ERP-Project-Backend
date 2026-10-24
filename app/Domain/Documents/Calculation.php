<?php

namespace App\Domain\Documents;

use App\Domain\Shared\Money;

/**
 * DocumentCalculator result (TOTALS of 05): subtotal = Σ gross, discount_total = Σ line and header discounts,
 * tax_total = Σ tax, rounding (BR-012), total = Σ line totals + rounding; the same in base currency; amount in words.
 */
final readonly class Calculation
{
    /**
     * @param  list<CalculatedLine>  $lines
     * @param  list<array{code: string, message: string, line_no?: int}>  $warnings
     */
    public function __construct(
        public array $lines,
        public Money $subtotal,
        public Money $discountTotal,
        public Money $taxTotal,
        public Money $rounding,
        public Money $total,
        public Money $baseRounding,
        public Money $baseTotal,
        public string $amountInWords,
        public array $warnings = [],
    ) {}

    public function line(int $lineNo): CalculatedLine
    {
        foreach ($this->lines as $l) {
            if ($l->lineNo === $lineNo) {
                return $l;
            }
        }
        throw new \OutOfBoundsException("No line {$lineNo}");
    }
}
