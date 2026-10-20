<?php

namespace App\Support;

/**
 * Formats decimal strings coming from the database to a fixed scale using string operations only
 * (money never passes through a float). "0" -> "0.00", "118450.5" -> "118450.50", "-12" -> "-12.00".
 */
final class Decimal
{
    public static function fixed(string|int|null $value, int $scale = 2): string
    {
        $v = trim((string) ($value ?? '0'));
        if ($v === '' || ! preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $v, $m)) {
            $v = '0';
            preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $v, $m);
        }
        [$sign, $int, $frac] = [$m[1], ltrim($m[2], '0') ?: '0', $m[3] ?? ''];
        if (strlen($frac) > $scale) {
            $frac = substr($frac, 0, $scale); // SQL already rounded to the column scale; extra digits are zeros
        }
        $out = $int.($scale > 0 ? '.'.str_pad($frac, $scale, '0') : '');

        return ($sign === '-' && trim($out, '0.') !== '') ? '-'.$out : $out;
    }
}
