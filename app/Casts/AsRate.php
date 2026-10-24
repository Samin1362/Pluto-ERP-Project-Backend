<?php

namespace App\Casts;

use App\Domain\Shared\Rate;

/** DECIMAL column ↔ Rate (scale 6). */
final class AsRate extends DecimalCast
{
    protected function type(): string
    {
        return Rate::class;
    }
}
