<?php

namespace App\Domain\Documents;

use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;

/**
 * Document-level inputs to DocumentCalculator: the company's rounding setting (BR-012) and inclusive-price setting
 * (06 §14), a header discount apportioned to stocked lines (06 §8), and for a foreign-currency document its exchange
 * rate to the base currency (null = base currency).
 */
final readonly class CalcOptions
{
    public const ROUNDINGS = ['none', 'nearest_1', 'nearest_5'];

    public Money $headerDiscount;

    public function __construct(
        public string $rounding = 'none',
        public bool $pricesIncludeTax = false,
        ?Money $headerDiscount = null,
        public string $currency = 'BDT',
        public ?Rate $exchangeRate = null,
    ) {
        if (! in_array($rounding, self::ROUNDINGS, true)) {
            throw new \InvalidArgumentException("Unknown rounding setting \"{$rounding}\"");
        }
        $this->headerDiscount = $headerDiscount ?? Money::zero();
    }
}
