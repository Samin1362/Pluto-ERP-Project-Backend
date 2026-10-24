<?php

namespace App\Domain\Shared;

/** "৳ 6,26,000.00": Bangladeshi grouping (last three digits, then pairs) for server-written text such as "When you save" effects. */
final class Taka
{
    public static function format(Money $amount, bool $symbol = true): string
    {
        [$whole, $paisa] = explode('.', $amount->abs()->toString());
        $grouped = strlen($whole) > 3
            ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)).','.substr($whole, -3)
            : $whole;

        return ($amount->isNegative() ? '−' : '').($symbol ? '৳ ' : '')."{$grouped}.{$paisa}";
    }
}
