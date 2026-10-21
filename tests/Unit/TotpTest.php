<?php

namespace Tests\Unit;

use App\Domain\Access\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** RFC 6238 appendix B test vectors (HMAC-SHA1, 8 digits) and the 6-digit, ±1 step rules of 07 §5. */
class TotpTest extends TestCase
{
    private const RFC_SECRET = '12345678901234567890';

    public static function vectors(): array
    {
        return [[59, '94287082'], [1111111109, '07081804'], [1111111111, '14050471'], [1234567890, '89005924'],
            [2000000000, '69279037'], [20000000000, '65353130']];
    }

    #[DataProvider('vectors')]
    public function test_rfc_6238_vectors(int $time, string $expected): void
    {
        $this->assertSame($expected, Totp::code(Totp::base32Encode(self::RFC_SECRET), Totp::step($time), 8));
    }

    public function test_base32_round_trip_and_rfc_4648_value(): void
    {
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32Encode(self::RFC_SECRET));
        $secret = Totp::newSecret();
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(20, strlen(Totp::base32Decode($secret)));
    }

    public function test_match_accepts_one_step_of_drift_only(): void
    {
        $s = Totp::newSecret();
        $t = 1_800_000_000;
        $step = Totp::step($t);
        $this->assertSame($step, Totp::match($s, Totp::code($s, $step), $t));
        $this->assertSame($step - 1, Totp::match($s, Totp::code($s, $step - 1), $t));
        $this->assertSame($step + 1, Totp::match($s, Totp::code($s, $step + 1), $t));
        $this->assertNull(Totp::match($s, Totp::code($s, $step - 2), $t));
        $this->assertNull(Totp::match($s, Totp::code($s, $step + 2), $t));
        $this->assertNull(Totp::match($s, '12345', $t));
        $this->assertNull(Totp::match($s, 'abcdef', $t));
    }

    public function test_otpauth_uri(): void
    {
        $this->assertSame('otpauth://totp/Pluto:rafiq%40meghna.example?secret=ABC&issuer=Pluto&algorithm=SHA1&digits=6&period=30',
            Totp::uri('ABC', 'rafiq@meghna.example', 'Pluto'));
    }
}
