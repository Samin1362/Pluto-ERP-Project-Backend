<?php

namespace Tests\Unit\Domain;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Domain\Shared\Rate;
use App\Domain\Shared\UnitCost;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\DivisionByZeroException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Money, Quantity, UnitCost and Rate (06 §1.9, BR-010; backlog P4.1-BE-01): fixed scales, HALF_UP, no floats. */
class DecimalValueTest extends TestCase
{
    public function test_tc_acc_019_three_lines_of_33_335_round_per_line_and_total_the_rounded_lines(): void
    {
        $lines = array_map(fn () => Money::of('33.335'), range(1, 3));
        foreach ($lines as $line) {
            $this->assertSame('33.34', $line->toString(), 'each line rounded half-up to 2 decimals');
        }
        $this->assertSame('100.02', Money::sum($lines)->toString(), 'total = sum of rounded lines, not round(100.005)');
        $this->assertSame('100.01', Money::of(BigDecimal::of('33.335')->multipliedBy(3))->toString(), 'rounding the unrounded total differs');
    }

    public static function scales(): array
    {
        return [
            'money' => [Money::class, '2.005', '2.01'], 'money negative half-up away from zero' => [Money::class, '-2.005', '-2.01'],
            'quantity' => [Quantity::class, '18', '18.000'], 'quantity rounds' => [Quantity::class, '1.2345', '1.235'],
            'unit cost' => [UnitCost::class, '845.94594', '845.9459'], 'rate' => [Rate::class, '0.0750004', '0.075000'],
        ];
    }

    #[DataProvider('scales')]
    public function test_each_type_keeps_its_scale_and_rounds_half_up(string $class, string $in, string $out): void
    {
        $this->assertSame($out, $class::of($in)->toString());
        $this->assertSame($out, (string) $class::of($in));
    }

    public function test_arithmetic_rounds_to_the_receivers_scale(): void
    {
        // 06 §8 worked example: 740 jars × 846.00 = 6,26,040.00; less 40.00 = 6,26,000.00; incoming unit cost 845.9459.
        $gross = Money::of('846.00')->multipliedBy(Quantity::of('740'));
        $this->assertSame('626040.00', $gross->toString());
        $net = $gross->minus('40.00');
        $this->assertSame('626000.00', $net->toString());
        $this->assertSame('845.9459', UnitCost::of($net->dividedBy(Quantity::of('740'), UnitCost::SCALE))->toString());

        // TC-ACC-020 parts: 10 × 2,340.00 = 23,400.00; 2% discount 468.00; 5% tax on 22,932.00 = 1,146.60.
        $line = Money::of('2340.00')->multipliedBy(10);
        $discount = $line->multipliedBy(Rate::of('0.02'));
        $tax = $line->minus($discount)->multipliedBy(Rate::of('0.05'));
        $this->assertSame(['23400.00', '468.00', '1146.60', '24078.60'],
            [$line->toString(), $discount->toString(), $tax->toString(), $line->minus($discount)->plus($tax)->toString()]);

        $this->assertSame('0.33', Money::of('1.00')->dividedBy(3, 2)->toString());
        $this->assertSame('-5.00', Money::of('5')->negated()->toString());
        $this->assertSame('5.00', Money::of('-5')->abs()->toString());
        $this->assertSame('0.00', Money::sum([])->toString());
    }

    public function test_comparisons_and_predicates(): void
    {
        $a = Money::of('10.00');
        $this->assertTrue($a->isEqualTo('10'));
        $this->assertTrue($a->isGreaterThan(Money::of('9.99')));
        $this->assertTrue($a->isLessThan('10.001'), 'compared exactly, not after rounding the other side');
        $this->assertSame(0, Money::zero()->compareTo(0));
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue(Money::of('-0.01')->isNegative());
        $this->assertTrue(Quantity::of('0.001')->isPositive());
        $this->assertFalse(Money::of('0.004')->isPositive(), '0.004 rounds to 0.00');
    }

    public function test_values_are_immutable(): void
    {
        $a = Money::of('1.00');
        $b = $a->plus('2.00');
        $this->assertSame('1.00', $a->toString());
        $this->assertSame('3.00', $b->toString());
        $this->assertInstanceOf(Money::class, $b);
    }

    public static function rejected(): array
    {
        return [[1.5], ['abc'], ['1,234.00'], [''], ['1e5'], ['12.3.4']];
    }

    #[DataProvider('rejected')]
    public function test_floats_and_non_decimal_strings_are_refused(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of($value);
    }

    public function test_floats_are_refused_as_operands_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1.00')->plus(0.1);
    }

    public function test_division_by_zero_throws(): void
    {
        $this->expectException(DivisionByZeroException::class);
        Money::of('1.00')->dividedBy('0', 2);
    }

    public function test_eloquent_casts_read_value_objects_and_write_fixed_scale_strings(): void
    {
        $model = new class extends Model {};
        $money = new AsMoney;
        $this->assertNull($money->get($model, 'total', null, []));
        $this->assertSame('6260.40', $money->get($model, 'total', '6260.4', [])->toString());
        $this->assertSame('100.00', $money->set($model, 'total', Money::of('100'), []));
        $this->assertSame('99.13', $money->set($model, 'total', '99.125', []));
        $this->assertSame('18.000', (new AsQuantity)->set($model, 'qty', 18, []));
        $this->expectException(InvalidArgumentException::class);
        $money->set($model, 'total', 1.25, []);
    }
}
