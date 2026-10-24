<?php

namespace App\Domain\Shared;

/** Stock quantity, 3 decimals (decimal(18,3) columns); a unit may allow fewer (units.decimals), checked by the caller. */
final class Quantity extends DecimalValue
{
    public const SCALE = 3;
}
