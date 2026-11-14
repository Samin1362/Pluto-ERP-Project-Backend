<?php

namespace App\Domain\Company;

/**
 * The colours the app derives from a company's brand colour (PEP-497): one pure place, so every screen and PDF of a
 * company agrees and the SPA does no colour maths. Contrast is the WCAG 2 ratio of relative luminances; "AA" is 4.5:1.
 * The paper and surface colours are the ones in frontend/public/lf-theme.css.
 */
final class BrandPalette
{
    public const DEFAULT_COLOR = '#1f3a5f';

    public const AA = 4.5;

    private const WHITE = '#ffffff';

    /** Text on a light brand colour (the dark paper of lf-theme.css). */
    private const NEAR_BLACK = '#0b1320';

    private const LIGHT_PAPER = '#f6f4ef';

    private const DARK_SURFACE = '#121c2b';

    private const DARK_RAISED = '#182436';

    /** Contrast the dark-theme brand reaches on the lightest dark surface (the default navy becomes about #7fa6d9). */
    private const DARK_TARGET = 6.0;

    /** BrandPalette of the contract, or null for no colour (the Pluto default of lf-theme.css applies). */
    public static function for(?string $color): ?array
    {
        if ($color === null || ! self::valid($color)) {
            return null;
        }
        $color = strtolower($color);

        return ['color' => $color, 'light' => self::light($color), 'dark' => self::dark($color)];
    }

    public static function valid(string $color): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1;
    }

    /** Why this colour cannot be used (plain words for the 422), or null when it reaches AA. */
    public static function problem(string $color): ?string
    {
        if (! self::valid($color)) {
            return 'Enter a colour like #1f3a5f';
        }
        $light = self::light(strtolower($color));
        if (self::contrast($light['brand'], $light['on_brand']) < self::AA) {
            return 'Button text would be hard to read on this colour. Choose a darker or a lighter one';
        }
        if (self::contrast($light['brand_ink'], self::LIGHT_PAPER) < self::AA || self::contrast($light['brand_ink'], $light['brand_soft']) < self::AA) {
            return 'Text in this colour would be hard to read on the page. Choose a darker one';
        }

        return null;
    }

    /** The colour for headings and rules on white paper (PDFs): the brand, darkened only when it is too light to read. */
    public static function printColor(?string $color, string $fallback): string
    {
        return $color !== null && self::valid($color) ? self::light(strtolower($color))['brand_ink'] : $fallback;
    }

    /** WCAG 2 contrast ratio of two #rrggbb colours (1 to 21). */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function light(string $color): array
    {
        $onWhite = self::contrast($color, self::WHITE) >= self::AA;
        $soft = self::mix($color, self::WHITE, 0.90);
        // Brand-coloured text: the colour itself, darkened in small steps until it reads on the paper and on its own tint.
        $ink = $color;
        for ($i = 1; $i <= 50 && (self::contrast($ink, self::LIGHT_PAPER) < self::AA || self::contrast($ink, $soft) < self::AA); $i++) {
            $ink = self::mix($color, '#000000', $i * 0.02);
        }

        return [
            'brand' => $color,
            // 12% darker; a light colour with dark text goes lighter instead, so the text stays readable on hover.
            'brand_hover' => self::mix($color, $onWhite ? '#000000' : self::WHITE, 0.12),
            'on_brand' => $onWhite ? self::WHITE : self::NEAR_BLACK,
            'brand_ink' => $ink,
            'brand_soft' => $soft,
            'focus' => $ink,
        ];
    }

    private static function dark(string $color): array
    {
        [$h, $s, $l] = self::hsl($color);
        // Same hue, lightened until it stands out on the dark surfaces.
        $brand = $color;
        while (self::contrast($brand, self::DARK_RAISED) < self::DARK_TARGET && $l < 1.0) {
            $l = min(1.0, $l + 0.01);
            $brand = self::fromHsl($h, $s, $l);
        }
        $hover = self::fromHsl($h, $s, min(1.0, $l + 0.075));

        return [
            'brand' => $brand,
            'brand_hover' => $hover,
            'on_brand' => self::contrast($brand, self::NEAR_BLACK) >= self::AA ? self::NEAR_BLACK : self::WHITE,
            'brand_ink' => $hover,
            'brand_soft' => self::mix($brand, self::DARK_SURFACE, 0.90),
            'focus' => $hover,
        ];
    }

    /** @return array{int, int, int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function hex(float $r, float $g, float $b): string
    {
        return sprintf('#%02x%02x%02x', self::byte($r), self::byte($g), self::byte($b));
    }

    private static function byte(float $v): int
    {
        return (int) max(0, min(255, round($v)));
    }

    /** $a with $weight (0 to 1) of $b mixed in. */
    private static function mix(string $a, string $b, float $weight): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);

        return self::hex($r1 + ($r2 - $r1) * $weight, $g1 + ($g2 - $g1) * $weight, $b1 + ($b2 - $b1) * $weight);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(function (int $c): float {
            $v = $c / 255;

            return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{float, float, float} hue 0-360, saturation and lightness 0-1 */
    private static function hsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (int $c): float => $c / 255, self::rgb($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d == 0.0) {
            return [0.0, 0.0, $l];
        }
        $s = $d / (1 - abs(2 * $l - 1));
        $h = match (true) {
            $max === $r => fmod(($g - $b) / $d + 6, 6),
            $max === $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        [$r, $g, $b] = match ((int) floor($h / 60) % 6) {
            0 => [$c, $x, 0.0],
            1 => [$x, $c, 0.0],
            2 => [0.0, $c, $x],
            3 => [0.0, $x, $c],
            4 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return self::hex(($r + $m) * 255, ($g + $m) * 255, ($b + $m) * 255);
    }
}
