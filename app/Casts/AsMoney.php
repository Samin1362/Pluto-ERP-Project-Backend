<?php

namespace App\Casts;

use App\Domain\Shared\Money;

/** DECIMAL column ↔ Money (scale 2). */
final class AsMoney extends DecimalCast
{
    protected function type(): string
    {
        return Money::class;
    }
}
