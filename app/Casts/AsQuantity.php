<?php

namespace App\Casts;

use App\Domain\Shared\Quantity;

/** DECIMAL column ↔ Quantity (scale 3). */
final class AsQuantity extends DecimalCast
{
    protected function type(): string
    {
        return Quantity::class;
    }
}
