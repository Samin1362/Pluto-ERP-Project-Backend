<?php

namespace Tests\Unit\Domain;

use App\Domain\Company\BrandPalette;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Company brand colours (PEP-497): derived once on the server, readable (WCAG AA 4.5:1) in the light and the dark theme. */
class BrandPaletteTest extends TestCase
{
    private const LIGHT_PAPER = '#f6f4ef';

    private const DARK_SURFACES = ['#0b1320', '#121c2b', '#182436'];

    public function test_no_colour_or_a_malformed_one_gives_no_palette(): void
    {
        $this->assertNull(BrandPalette::for(null));
        $this->assertNull(BrandPalette::for('teal'));
        $this->assertNull(BrandPalette::for('#12345'));
        $this->assertSame('Enter a colour like #1f3a5f', BrandPalette::problem('#12345g'));
    }

    public function test_contrast_is_the_wcag_ratio(): void
    {
        $this->assertEqualsWithDelta(21.0, BrandPalette::contrast('#000000', '#ffffff'), 0.001);
        $this->assertEqualsWithDelta(1.0, BrandPalette::contrast('#1f3a5f', '#1f3a5f'), 0.001);
        $this->assertEqualsWithDelta(4.54, BrandPalette::contrast('#767676', '#ffffff'), 0.01);
    }

    public function test_the_pluto_default_gives_the_tokens_of_the_theme_closely(): void
    {
        $p = BrandPalette::for('#1F3A5F');
        $this->assertSame('#1f3a5f', $p['color'], 'returned in lower case');
        $this->assertSame(['brand', 'brand_hover', 'on_brand', 'brand_ink', 'brand_soft', 'focus'], array_keys($p['light']));
        $this->assertSame(array_keys($p['light']), array_keys($p['dark']));
        // lf-theme.css today
        $light = ['brand' => '#1f3a5f', 'brand_hover' => '#172d4a', 'on_brand' => '#ffffff', 'brand_ink' => '#1f3a5f', 'brand_soft' => '#e8eef6', 'focus' => '#1f3a5f'];
        $dark = ['brand' => '#7fa6d9', 'brand_hover' => '#9bbbe4', 'on_brand' => '#0b1320', 'brand_ink' => '#9bbbe4', 'brand_soft' => '#1a2a40', 'focus' => '#9bbbe4'];
        foreach (['light' => $light, 'dark' => $dark] as $theme => $tokens) {
            foreach ($tokens as $name => $hex) {
                $this->assertLessThanOrEqual(16, $this->distance($hex, $p[$theme][$name]), "$theme $name: {$p[$theme][$name]} is not close to $hex");
            }
        }
        $this->assertSame('#1f3a5f', $p['light']['brand']);
        $this->assertSame('#1f3a5f', $p['light']['brand_ink'], 'dark enough already: not darkened');
        $this->assertSame('#ffffff', $p['light']['on_brand']);
        $this->assertSame('#0b1320', $p['dark']['on_brand']);
    }

    public static function colours(): array
    {
        return [
            'Pluto blue' => ['#1f3a5f'], 'teal' => ['#0f766e'], 'green' => ['#166534'], 'maroon' => ['#7f1d1d'], 'purple' => ['#6b21a8'],
            'indigo' => ['#3730a3'], 'slate' => ['#334155'], 'burnt orange' => ['#9a3412'],
            'yellow (very light)' => ['#ffd400'], 'white' => ['#ffffff'], 'near black (very dark)' => ['#050505'], 'black' => ['#000000'],
            'pure red' => ['#ff0000'], 'pure blue' => ['#0000ff'], 'lime' => ['#a3e635'],
        ];
    }

    #[DataProvider('colours')]
    public function test_every_accepted_colour_is_readable_in_both_themes(string $color): void
    {
        $this->assertNull(BrandPalette::problem($color));
        $p = BrandPalette::for($color);
        foreach (['light', 'dark'] as $theme) {
            foreach ($p[$theme] as $name => $hex) {
                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $hex, "$theme $name");
            }
            $t = $p[$theme];
            $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($t['brand'], $t['on_brand']), "$theme: button text on the brand");
            $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($t['brand_ink'], $t['brand_soft']), "$theme: brand text on its tint");
        }
        $this->assertSame($color, $p['light']['brand'], 'the light brand is the colour the company chose');
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($p['light']['brand_ink'], self::LIGHT_PAPER));
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($p['light']['brand_ink'], '#ffffff'));
        $this->assertGreaterThanOrEqual(3.0, BrandPalette::contrast($p['light']['focus'], self::LIGHT_PAPER), 'focus ring');
        foreach (self::DARK_SURFACES as $surface) {
            $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($p['dark']['brand'], $surface), "dark brand on $surface");
            $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($p['dark']['brand_ink'], $surface), "dark brand text on $surface");
        }
    }

    public function test_a_light_colour_gets_dark_button_text_and_darker_brand_text(): void
    {
        $p = BrandPalette::for('#ffd400');
        $this->assertSame('#0b1320', $p['light']['on_brand']);
        $this->assertNotSame('#ffd400', $p['light']['brand_ink'], 'yellow text would not read on paper');
        $this->assertSame('#ffd400', $p['dark']['brand'], 'already stands out on the dark surface');
        $this->assertSame($p['light']['brand_ink'], BrandPalette::printColor('#ffd400', '#0b6b5e'), 'PDF headings use the readable one');
        $this->assertSame('#0b6b5e', BrandPalette::printColor(null, '#0b6b5e'));
        $this->assertSame('#7f1d1d', BrandPalette::printColor('#7f1d1d', '#0b6b5e'));
    }

    public function test_a_very_dark_colour_is_lightened_for_the_dark_theme(): void
    {
        $p = BrandPalette::for('#050505');
        $this->assertSame('#ffffff', $p['light']['on_brand']);
        $this->assertGreaterThanOrEqual(6.0, BrandPalette::contrast($p['dark']['brand'], '#182436'));
    }

    public function test_a_colour_too_light_for_white_text_and_too_dark_for_dark_text_is_refused(): void
    {
        // mid grey: 4.4:1 against white and 4.2:1 against the near-black text
        $this->assertLessThan(4.5, BrandPalette::contrast('#797979', '#ffffff'));
        $this->assertLessThan(4.5, BrandPalette::contrast('#797979', '#0b1320'));
        $this->assertStringContainsString('hard to read', (string) BrandPalette::problem('#797979'));
    }

    private function distance(string $a, string $b): int
    {
        $d = 0;
        foreach ([1, 3, 5] as $i) {
            $d = max($d, abs(hexdec(substr($a, $i, 2)) - hexdec(substr($b, $i, 2))));
        }

        return (int) $d;
    }
}
