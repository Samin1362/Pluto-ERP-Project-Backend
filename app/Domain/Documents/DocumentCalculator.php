<?php

namespace App\Domain\Documents;

use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Totals of a trade document (06 §5, §8, §14; BR-010, BR-011, BR-012, BR-036; backlog P4.1-BE-02). Pure: no database,
 * tax rates come in on the lines (only lines with a user-defined rule are taxed, BR-036). The server's result is the
 * authority; the UI only shows it (DEC-09).
 *
 * Per line (exclusive prices): gross = qty × price; discount = gross × disc% ÷ 100; minus its share of the header
 * discount; net = what remains; tax = net × rate ÷ 100; total = net + tax. Inclusive prices (06 §14): the amount
 * after discounts already contains tax, net = amount × 100 ÷ (100 + rate), tax = amount − net. Every amount is
 * rounded HALF_UP to 2 decimals at the line; document totals are sums of rounded lines.
 */
final class DocumentCalculator
{
    /** @param  list<CalcLine>  $lines */
    public function calculate(array $lines, CalcOptions $options = new CalcOptions): Calculation
    {
        if ($lines === []) {
            throw new InvalidArgumentException('A document needs at least one line.');
        }
        $hundred = BigDecimal::of(100);
        $afterLineDiscount = [];
        $discounts = [];
        $grosses = [];
        foreach ($lines as $l) {
            if (! $l->quantity->isPositive() || $l->unitPrice->isNegative() || $l->discountPct->isNegative() || $l->discountPct->isGreaterThan(100)) {
                throw new InvalidArgumentException("Line {$l->lineNo}: quantity must be > 0, price ≥ 0 and discount 0–100 %.");
            }
            $gross = Money::of($l->quantity->toBigDecimal()->multipliedBy($l->unitPrice->toBigDecimal()));
            $discount = Money::of($gross->toBigDecimal()->multipliedBy($l->discountPct->toBigDecimal())->dividedBy($hundred, 12, RoundingMode::HalfUp));
            $grosses[$l->lineNo] = $gross;
            $discounts[$l->lineNo] = $discount;
            $afterLineDiscount[$l->lineNo] = $gross->minus($discount);
        }
        $shares = $this->apportion($lines, $afterLineDiscount, $options->headerDiscount);

        $out = [];
        foreach ($lines as $l) {
            $amount = $afterLineDiscount[$l->lineNo]->minus($shares[$l->lineNo]);
            $rate = $l->taxRatePct ?? Rate::zero();
            if ($options->pricesIncludeTax && ! $rate->isZero()) {
                $net = Money::of($amount->toBigDecimal()->multipliedBy($hundred)->dividedBy($hundred->plus($rate->toBigDecimal()), 12, RoundingMode::HalfUp));
                $tax = $amount->minus($net);
            } else {
                $net = $amount;
                $tax = Money::of($net->toBigDecimal()->multipliedBy($rate->toBigDecimal())->dividedBy($hundred, 12, RoundingMode::HalfUp));
            }
            $total = $net->plus($tax);
            [$baseNet, $baseTax] = [$this->toBase($net, $options), $this->toBase($tax, $options)];
            $out[] = new CalculatedLine($l->lineNo, $grosses[$l->lineNo], $discounts[$l->lineNo], $shares[$l->lineNo], $net, $tax, $total,
                $baseNet, $baseTax, $baseNet->plus($baseTax), $rate->isZero() ? null : $l->taxRuleId);
        }

        $linesTotal = Money::sum(array_map(fn (CalculatedLine $c) => $c->total, $out));
        $rounding = $this->rounding($linesTotal, $options->rounding);
        $total = $linesTotal->plus($rounding);
        $baseRounding = $this->toBase($rounding, $options);

        return new Calculation(
            lines: $out,
            subtotal: Money::sum(array_map(fn (CalculatedLine $c) => $c->gross, $out)),
            discountTotal: Money::sum(array_map(fn (CalculatedLine $c) => $c->discountTotal(), $out)),
            taxTotal: Money::sum(array_map(fn (CalculatedLine $c) => $c->tax, $out)),
            rounding: $rounding,
            total: $total,
            baseRounding: $baseRounding,
            baseTotal: Money::sum(array_map(fn (CalculatedLine $c) => $c->baseTotal, $out))->plus($baseRounding),
            amountInWords: AmountInWords::of($total, $options->currency),
        );
    }

    /**
     * The header discount split over the stocked lines (all lines if none is stocked) in proportion to their value
     * after line discounts; each share rounded HALF_UP, the last eligible line takes the remainder so the shares add up.
     *
     * @param  list<CalcLine>  $lines
     * @param  array<int, Money>  $values
     * @return array<int, Money>
     */
    private function apportion(array $lines, array $values, Money $header): array
    {
        $shares = array_map(fn () => Money::zero(), $values);
        if ($header->isZero()) {
            return $shares;
        }
        if ($header->isNegative()) {
            throw new InvalidArgumentException('The header discount cannot be negative.');
        }
        $eligible = array_values(array_filter($lines, fn (CalcLine $l) => $l->stocked)) ?: $lines;
        $base = Money::sum(array_map(fn (CalcLine $l) => $values[$l->lineNo], $eligible));
        if ($header->isGreaterThan($base)) {
            throw new InvalidArgumentException("The header discount {$header} is more than the lines it applies to ({$base}).");
        }
        $left = $header;
        foreach ($eligible as $i => $l) {
            $share = $i === count($eligible) - 1 ? $left
                : Money::of($header->toBigDecimal()->multipliedBy($values[$l->lineNo]->toBigDecimal())->dividedBy($base->toBigDecimal(), 12, RoundingMode::HalfUp));
            $shares[$l->lineNo] = $share;
            $left = $left->minus($share);
        }

        return $shares;
    }

    /** BR-012: the difference to the nearest 1 or 5 taka (HALF_UP), posted to the rounding ledger; none = 0. */
    private function rounding(Money $total, string $setting): Money
    {
        $step = ['none' => null, 'nearest_1' => 1, 'nearest_5' => 5][$setting];
        if ($step === null) {
            return Money::zero();
        }
        $rounded = $total->toBigDecimal()->dividedBy($step, 0, RoundingMode::HalfUp)->multipliedBy($step);

        return Money::of($rounded)->minus($total);
    }

    /** Base-currency amount, rounded per line (06 §8); a base-currency document keeps its amounts. */
    private function toBase(Money $amount, CalcOptions $options): Money
    {
        return $options->exchangeRate === null ? $amount : Money::of($amount->toBigDecimal()->multipliedBy($options->exchangeRate->toBigDecimal()));
    }
}
