<?php

namespace App\Domain\Shared;

/** Money amount, 2 decimals (BR-010): line amounts, totals, balances. Currency is carried by the document, not here. */
final class Money extends DecimalValue
{
    public const SCALE = 2;
}
