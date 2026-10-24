<?php

namespace App\Casts;

use App\Domain\Shared\UnitCost;

/** DECIMAL column ↔ UnitCost (scale 4). */
final class AsUnitCost extends DecimalCast
{
    protected function type(): string
    {
        return UnitCost::class;
    }
}
