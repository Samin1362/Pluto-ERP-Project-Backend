<?php

namespace App\Domain\Access;

/**
 * Time-based one-time passwords, RFC 6238 with the parameters of 07 §5: HMAC-SHA1, 30-second steps, 6 digits,
 * accepting the previous and next step for clock drift. Secrets are 160-bit, exchanged as RFC 4648 base32.
 */
final class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    public const WINDOW = 1;

    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(?int $unixTime = null): int
    {
        return intdiv($unixTime ?? time(), self::PERIOD);
    }

    /** The code for one time step (RFC 4226 dynamic truncation). */
    public static function code(string $base32Secret, int $step, int $digits = self::DIGITS, string $algo = 'sha1'): string
    {
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash = hash_hmac($algo, $counter, self::base32Decode($base32Secret), true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** The matching time step within the drift window, or null. Comparison is constant-time per candidate. */
    public static function match(string $base32Secret, string $code, ?int $unixTime = null): ?int
    {
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }
        $now = self::step($unixTime);
        $found = null;
        for ($s = $now - self::WINDOW; $s <= $now + self::WINDOW; $s++) {
            if (hash_equals(self::code($base32Secret, $s), $code) && $found === null) {
                $found = $s;
            }
        }

        return $found;
    }

    public static function uri(string $base32Secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account)
            .'?secret='.$base32Secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $b) {
            $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=-]/', '', $text));
        $bits = '';
        foreach (str_split($text) as $ch) {
            $i = strpos(self::B32, $ch);
            if ($i === false) {
                throw new \InvalidArgumentException('Invalid base32 secret');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
