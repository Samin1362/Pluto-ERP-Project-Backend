<?php

namespace Tests\Unit\Domain;

use App\Domain\Inventory\AverageCostCalculator;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\UnitCost;
use PHPUnit\Framework\TestCase;

/** Moving weighted average (BR-016, BR-017; R-16, R-17; backlog P4.1-BE-06). Pure. */
class AverageCostCalculatorTest extends TestCase
{
    private AverageCostCalculator $c;

    protected function setUp(): void
    {
        $this->c = new AverageCostCalculator;
    }

    private function in(string $qty, string $value, string $avg, string $inQty, string $inValue)
    {
        return $this->c->afterIn(Quantity::of($qty), Money::of($value), UnitCost::of($avg), Quantity::of($inQty), Money::of($inValue));
    }

    private function out(string $qty, string $value, string $avg, string $outQty)
    {
        return $this->c->afterOut(Quantity::of($qty), Money::of($value), UnitCost::of($avg), Quantity::of($outQty));
    }

    private function pos($p): array
    {
        return [(string) $p->qty, (string) $p->value, (string) $p->avg, (string) $p->trueUp];
    }

    public function test_tc_pur_003_100_at_800_plus_50_at_860_averages_820(): void
    {
        $this->assertSame(['150.000', '123000.00', '820.0000', '0.00'], $this->pos($this->in('100', '80000.00', '800', '50', '43000.00')));
    }

    public function test_06_s8_first_receipt_takes_the_incoming_unit_cost(): void
    {
        // BILL-2026-0224: 740 jars for 6,26,000.00 into an empty warehouse → 845.9459 (06 §8).
        $this->assertSame(['740.000', '626000.00', '845.9459', '0.00'], $this->pos($this->in('0', '0', '0', '740', '626000.00')));
    }

    public function test_tc_pur_004_with_nothing_on_hand_the_incoming_cost_is_the_average(): void
    {
        $this->assertSame(['10.000', '1200.00', '120.0000', '0.00'], $this->pos($this->in('0', '0.00', '500', '10', '1200.00')));
        // A rounding residue left at zero quantity is cleared to COGS instead of skewing the new average.
        $this->assertSame(['10.000', '1200.00', '120.0000', '-0.03'], $this->pos($this->in('0', '0.03', '500', '10', '1200.00')));
    }

    public function test_tc_inv_008_r16_negative_stock_true_up(): void
    {
        // Sell 5 with 0 on hand at avg 100 (−5, −500), then receive 10 @ 120: true-up −100 (Dr COGS 100), value = 5 × 120.
        [$neg, $cost] = $this->out('0', '0.00', '100', '5');
        $this->assertSame(['-5.000', '-500.00', '500.00'], [(string) $neg->qty, (string) $neg->value, (string) $cost]);
        $this->assertSame(['5.000', '600.00', '120.0000', '-100.00'], $this->pos($this->in('-5', '-500.00', '100', '10', '1200.00')));
        // Exactly covering the shortfall: zero and zero.
        $this->assertSame(['0.000', '0.00', '100.0000', '-100.00'], $this->pos($this->in('-5', '-500.00', '100', '5', '600.00')));
        // Partly covering: the 2 covered units re-cost 100 → 120 (true-up −40); the 3 still short stay at 100.
        $this->assertSame(['-3.000', '-300.00', '100.0000', '-40.00'], $this->pos($this->in('-5', '-500.00', '100', '2', '240.00')));
    }

    public function test_stock_out_costs_at_the_average_and_the_last_units_take_the_remaining_value(): void
    {
        [$p, $cost] = $this->out('100', '80000.00', '800', '30');
        $this->assertSame(['70.000', '56000.00', '24000.00'], [(string) $p->qty, (string) $p->value, (string) $cost]);
        // 3 units worth 1000.00 (avg 333.3333): the last unit takes 333.34, not 333.33, so value ends at 0.00.
        [$p, $cost] = $this->out('3', '1000.00', '333.3333', '2');
        $this->assertSame(['1.000', '333.33', '666.67'], [(string) $p->qty, (string) $p->value, (string) $cost]);
        [$p, $cost] = $this->out('1', '333.33', '333.3333', '1');
        $this->assertSame(['0.000', '0.00', '333.33'], [(string) $p->qty, (string) $p->value, (string) $cost]);
        // Beyond the stock (negative allowed): the rest at the last average (R-16).
        [$p, $cost] = $this->out('100', '80000.00', '800', '110');
        $this->assertSame(['-10.000', '-8000.00', '88000.00'], [(string) $p->qty, (string) $p->value, (string) $cost]);
    }

    public function test_r17_value_never_goes_below_zero_while_quantity_is_positive(): void
    {
        [$p, $cost] = $this->out('10', '50.00', '800', '5');
        $this->assertSame(['5.000', '0.00', '50.00'], [(string) $p->qty, (string) $p->value, (string) $cost]);
    }
}
