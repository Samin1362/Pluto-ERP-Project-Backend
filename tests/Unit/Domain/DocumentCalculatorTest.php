<?php

namespace Tests\Unit\Domain;

use App\Domain\Documents\CalcLine;
use App\Domain\Documents\CalcOptions;
use App\Domain\Documents\DocumentCalculator;
use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\Rate;
use App\Domain\Shared\UnitCost;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DocumentCalculator (06 §5, §8, §14; BR-010/011/012/036; backlog P4.1-BE-02). Pure: no database. */
class DocumentCalculatorTest extends TestCase
{
    private function line(int $no, string $qty, string $price, string $disc = '0', ?string $tax = null, bool $stocked = true, ?int $rule = null): CalcLine
    {
        return new CalcLine($no, Quantity::of($qty), UnitCost::of($price), Rate::of($disc), $tax === null ? null : ($rule ?? 7), Rate::ofNullable($tax), $stocked);
    }

    private function calc(array $lines, ?CalcOptions $o = null)
    {
        return (new DocumentCalculator)->calculate($lines, $o ?? new CalcOptions);
    }

    public function test_tc_acc_020_line_with_discount_and_tax(): void
    {
        $l = $this->calc([$this->line(1, '10', '2340.00', '2', '5')])->line(1);
        $this->assertSame(['23400.00', '468.00', '22932.00', '1146.60', '24078.60'],
            [(string) $l->gross, (string) $l->discount, (string) $l->net, (string) $l->tax, (string) $l->total]);
    }

    public function test_tc_acc_021_rounding_to_nearest_1_and_nearest_5(): void
    {
        $r = $this->calc([$this->line(1, '1', '118449.60')], new CalcOptions(rounding: 'nearest_1'));
        $this->assertSame(['0.40', '118450.00'], [(string) $r->rounding, (string) $r->total]);
        $r = $this->calc([$this->line(1, '1', '118447.40')], new CalcOptions(rounding: 'nearest_5'));
        $this->assertSame(['-2.40', '118445.00'], [(string) $r->rounding, (string) $r->total]);
        $r = $this->calc([$this->line(1, '1', '118447.50')], new CalcOptions(rounding: 'nearest_5'));
        $this->assertSame(['2.50', '118450.00'], [(string) $r->rounding, (string) $r->total], 'half way rounds up');
        $r = $this->calc([$this->line(1, '1', '118449.60')]);
        $this->assertSame(['0.00', '118449.60'], [(string) $r->rounding, (string) $r->total]);
    }

    public function test_tc_acc_022_only_lines_with_a_tax_rule_are_taxed(): void
    {
        $r = $this->calc([$this->line(1, '4', '250.00', tax: '7.5', rule: 3), $this->line(2, '4', '250.00')]);
        $this->assertSame(['75.00', 3], [(string) $r->line(1)->tax, $r->line(1)->taxRuleId]);
        $this->assertSame(['0.00', null], [(string) $r->line(2)->tax, $r->line(2)->taxRuleId]);
        $this->assertSame(['2000.00', '75.00', '2075.00'], [(string) $r->subtotal, (string) $r->taxTotal, (string) $r->total]);
    }

    public function test_06_s8_worked_example_bill_2026_0224_header_discount_lowers_the_stock_line(): void
    {
        $r = $this->calc([$this->line(1, '740', '846.00')], new CalcOptions(headerDiscount: Money::of('40.00')));
        $l = $r->line(1);
        $this->assertSame(['626040.00', '0.00', '40.00', '626000.00', '626000.00'],
            [(string) $l->gross, (string) $l->discount, (string) $l->headerDiscount, (string) $l->net, (string) $l->total]);
        $this->assertSame(['626040.00', '40.00', '626000.00'], [(string) $r->subtotal, (string) $r->discountTotal, (string) $r->total]);
        $this->assertSame('Taka six lakh twenty-six thousand only', $r->amountInWords);
    }

    public function test_06_s5_worked_example_inv_2026_0587_totals_the_rounded_lines(): void
    {
        $r = $this->calc([$this->line(1, '40', '940'), $this->line(2, '12', '2340'), $this->line(3, '120', '255'), $this->line(4, '96', '220'),
            $this->line(5, '6', '125'), $this->line(6, '2', '150', stocked: false)]);
        $this->assertSame(['118450.00', '0.00', '118450.00'], [(string) $r->subtotal, (string) $r->taxTotal, (string) $r->total]);
        $this->assertSame('Taka one lakh eighteen thousand four hundred fifty only', $r->amountInWords);
    }

    public function test_inclusive_prices_take_the_tax_out_of_the_amount(): void
    {
        $l = $this->calc([$this->line(1, '1', '1150.00', tax: '15')], new CalcOptions(pricesIncludeTax: true))->line(1);
        $this->assertSame(['1150.00', '1000.00', '150.00', '1150.00'], [(string) $l->gross, (string) $l->net, (string) $l->tax, (string) $l->total]);
        $l = $this->calc([$this->line(1, '3', '100.00', '10', '7.5')], new CalcOptions(pricesIncludeTax: true))->line(1);
        $this->assertSame(['300.00', '30.00', '251.16', '18.84', '270.00'], // 270 × 100 ÷ 107.5 = 251.1627…
            [(string) $l->gross, (string) $l->discount, (string) $l->net, (string) $l->tax, (string) $l->total]);
        $untaxed = $this->calc([$this->line(1, '2', '50.00')], new CalcOptions(pricesIncludeTax: true))->line(1);
        $this->assertSame(['100.00', '0.00'], [(string) $untaxed->net, (string) $untaxed->tax]);
    }

    public function test_header_discount_goes_to_stocked_lines_by_value_last_line_takes_the_remainder(): void
    {
        // 100.00 over 300 + 600 + 200 of stock (a 500 service takes none): 27.27 + 54.55 + 18.18 = 100.00.
        $r = $this->calc([$this->line(1, '3', '100'), $this->line(2, '6', '100'), $this->line(3, '1', '500', stocked: false), $this->line(4, '2', '100')],
            new CalcOptions(headerDiscount: Money::of('100.00')));
        $this->assertSame(['27.27', '54.55', '0.00', '18.18'], array_map(fn ($l) => (string) $l->headerDiscount, $r->lines));
        $this->assertSame('100.00', (string) $r->discountTotal);
        $this->assertSame('1500.00', (string) $r->total);
        // Without any stocked line the whole document shares it.
        $r = $this->calc([$this->line(1, '1', '300', stocked: false), $this->line(2, '1', '100', stocked: false)], new CalcOptions(headerDiscount: Money::of('40')));
        $this->assertSame(['30.00', '10.00'], array_map(fn ($l) => (string) $l->headerDiscount, $r->lines));
    }

    public function test_foreign_currency_base_amounts_are_rounded_per_line(): void
    {
        // USD 10.005 → 10.01 per line; at 110.5555: 1106.66 per line (not 3 × 10.01 × rate rounded once).
        $r = $this->calc([$this->line(1, '1', '10.005'), $this->line(2, '1', '10.005'), $this->line(3, '1', '10.005')],
            new CalcOptions(rounding: 'nearest_1', currency: 'USD', exchangeRate: Rate::of('110.5555')));
        $this->assertSame(['10.01', '1106.66'], [(string) $r->line(1)->total, (string) $r->line(1)->baseTotal]);
        $this->assertSame(['30.03', '-0.03', '30.00'], [(string) $r->subtotal, (string) $r->rounding, (string) $r->total]);
        $this->assertSame(['-3.32', '3316.66'], [(string) $r->baseRounding, (string) $r->baseTotal]); // 3 × 1106.66 − 3.32
        $this->assertSame('USD thirty only', $r->amountInWords);
        $base = $this->calc([$this->line(1, '1', '5.00')])->line(1);
        $this->assertSame('5.00', (string) $base->baseTotal, 'base-currency documents keep their amounts');
    }

    public static function words(): array
    {
        return [
            ['57882.00', 'Taka fifty-seven thousand eight hundred eighty-two only'],
            ['274700.00', 'Taka two lakh seventy-four thousand seven hundred only'],
            ['123456789.40', 'Taka twelve crore thirty-four lakh fifty-six thousand seven hundred eighty-nine and forty paisa only'],
            ['1000000000000.00', 'Taka one lakh crore only'],
            ['0.05', 'Taka zero and five paisa only'],
            ['-15.00', 'Minus Taka fifteen only'],
        ];
    }

    #[DataProvider('words')]
    public function test_amount_in_words_with_lakh_and_crore(string $amount, string $expected): void
    {
        $this->assertSame($expected, AmountInWords::of(Money::of($amount)));
    }

    public function test_rejects_impossible_input(): void
    {
        foreach ([
            fn () => $this->calc([]),
            fn () => $this->calc([$this->line(1, '0', '10')]),
            fn () => $this->calc([$this->line(1, '1', '-1')]),
            fn () => $this->calc([$this->line(1, '1', '10', '101')]),
            fn () => $this->calc([$this->line(1, '1', '10')], new CalcOptions(headerDiscount: Money::of('10.01'))),
            fn () => new CalcOptions(rounding: 'nearest_10'),
        ] as $i => $f) {
            try {
                $f();
                $this->fail("case {$i} did not throw");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
