<?php

namespace App\Domain\Shared;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * English amount in words with Bangladeshi grouping (crore, lakh, thousand), as on the design's documents:
 * "Taka fifty-seven thousand eight hundred eighty-two only", "Taka two lakh seventy-four thousand seven hundred only".
 * Paisa are added as "and forty paisa". Other currencies use their code ("USD one thousand … and five cents only").
 */
final class AmountInWords
{
    private const ONES = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen',
        'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];

    private const TENS = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];

    public static function of(Money $amount, string $currency = 'BDT'): string
    {
        $abs = $amount->abs()->toBigDecimal();
        $whole = $abs->toScale(0, RoundingMode::Down)->toBigInteger();
        $paisa = (int) $abs->minus($whole)->multipliedBy(100)->toScale(0, RoundingMode::Down)->toString();
        [$major, $minor] = $currency === 'BDT' ? ['Taka', 'paisa'] : [$currency, 'cents'];

        $words = $whole->isZero() ? 'zero' : self::indian($whole);
        $text = "{$major} {$words}".($paisa > 0 ? ' and '.self::below100($paisa)." {$minor}" : '').' only';

        return $amount->isNegative() ? "Minus {$text}" : $text;
    }

    /** crore (10^7) groups repeat: 1,00,00,00,000 = one hundred crore. */
    private static function indian(BigInteger $n): string
    {
        $crore = BigInteger::of(10_000_000);
        if ($n->isGreaterThanOrEqualTo($crore)) {
            $rest = $n->mod($crore);

            return trim(self::indian($n->quotient($crore)).' crore'.($rest->isZero() ? '' : ' '.self::indian($rest)));
        }
        $v = $n->toInt();
        $parts = [];
        foreach ([[100_000, 'lakh'], [1_000, 'thousand'], [100, 'hundred']] as [$size, $name]) {
            if ($v >= $size) {
                $parts[] = self::below100(intdiv($v, $size)).' '.$name;
                $v %= $size;
            }
        }
        if ($v > 0) {
            $parts[] = self::below100($v);
        }

        return implode(' ', $parts);
    }

    private static function below100(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return self::TENS[intdiv($n, 10)].($n % 10 ? '-'.self::ONES[$n % 10] : '');
    }
}
