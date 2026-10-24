<?php

namespace App\Domain\Shared;

/** Rate, 6 decimals: tax and discount percentages as fractions or percents, exchange rates (06 §1.9). */
final class Rate extends DecimalValue
{
    public const SCALE = 6;
}
