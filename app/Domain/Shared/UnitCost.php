<?php

namespace App\Domain\Shared;

/** Unit cost or price per unit, 4 decimals (moving-average cost, BR-017; incoming cost per unit). */
final class UnitCost extends DecimalValue
{
    public const SCALE = 4;
}
