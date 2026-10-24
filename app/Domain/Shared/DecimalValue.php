<?php

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use Stringable;

/**
 * Base of the fixed-scale decimal value objects (06 §1.9, BR-010; backlog P4.1-BE-01): Money (2), Quantity (3),
 * UnitCost (4) and Rate (6). Immutable; built from decimal strings, ints or BigDecimal — never from floats, which
 * throw. Every result is rounded HALF_UP to the class's scale, so arithmetic on money is line-level rounding by
 * construction; totals are sums of already-rounded values. Cross-type maths goes through toBigDecimal() and of().
 */
abstract class DecimalValue implements Stringable
{
    /** Digits after the decimal point. */
    public const SCALE = 0;

    final protected function __construct(private readonly BigDecimal $value) {}

    /**
     * From a decimal string ("1234.5"), an int, a BigDecimal or another value object, rounded HALF_UP to the scale.
     * `float` is in the signature only so that PHP cannot quietly coerce it to a string: it always throws.
     */
    public static function of(self|BigNumber|int|float|string $value): static
    {
        return new static(self::operand($value)->toScale(static::SCALE, RoundingMode::HalfUp));
    }

    /** Like of(), but null stays null (nullable columns and optional input). */
    public static function ofNullable(self|BigNumber|int|float|string|null $value): ?static
    {
        return $value === null ? null : static::of($value);
    }

    public static function zero(): static
    {
        return static::of(0);
    }

    /** Sum of values of this type (each already rounded, so the total is the sum of rounded parts, BR-010). */
    public static function sum(iterable $values): static
    {
        $total = BigDecimal::zero();
        foreach ($values as $v) {
            $total = $total->plus(self::operand($v));
        }

        return static::of($total);
    }

    public function plus(self|BigNumber|int|float|string $that): static
    {
        return static::of($this->value->plus(self::operand($that)));
    }

    public function minus(self|BigNumber|int|float|string $that): static
    {
        return static::of($this->value->minus(self::operand($that)));
    }

    /** Product rounded HALF_UP to this type's scale (e.g. Money × Quantity → Money). */
    public function multipliedBy(self|BigNumber|int|float|string $that): static
    {
        return static::of($this->value->multipliedBy(self::operand($that)));
    }

    /**
     * Quotient with an explicit result scale and HALF_UP rounding; returns a BigDecimal because the caller decides
     * what the result is (a rate, a unit cost, a share). Dividing by zero throws.
     */
    public function dividedBy(self|BigNumber|int|float|string $that, int $scale): BigDecimal
    {
        return $this->value->dividedBy(self::operand($that), $scale, RoundingMode::HalfUp);
    }

    public function negated(): static
    {
        return new static($this->value->negated());
    }

    public function abs(): static
    {
        return new static($this->value->abs());
    }

    public function isZero(): bool
    {
        return $this->value->isZero();
    }

    public function isNegative(): bool
    {
        return $this->value->isNegative();
    }

    public function isPositive(): bool
    {
        return $this->value->isPositive();
    }

    public function compareTo(self|BigNumber|int|float|string $that): int
    {
        return $this->value->compareTo(self::operand($that));
    }

    public function isEqualTo(self|BigNumber|int|float|string $that): bool
    {
        return $this->compareTo($that) === 0;
    }

    public function isGreaterThan(self|BigNumber|int|float|string $that): bool
    {
        return $this->compareTo($that) > 0;
    }

    public function isLessThan(self|BigNumber|int|float|string $that): bool
    {
        return $this->compareTo($that) < 0;
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->value;
    }

    /** Fixed scale, e.g. Money "1234.50", Quantity "18.000" — the API's decimal-string format (05 §1). */
    public function toString(): string
    {
        return $this->value->toString();
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private static function operand(mixed $v): BigDecimal
    {
        if (is_float($v)) {
            throw new InvalidArgumentException('Floats are not accepted for money, quantities or rates (BR-010); pass a decimal string.');
        }

        return $v instanceof self ? $v->value : self::parse($v);
    }

    private static function parse(BigNumber|int|string $v): BigDecimal
    {
        if (is_string($v) && ! preg_match('/^\s*[-+]?(\d+(\.\d*)?|\.\d+)\s*$/', $v)) {
            throw new InvalidArgumentException("Not a decimal number: \"{$v}\"");
        }

        return $v instanceof BigNumber ? $v->toBigDecimal() : BigDecimal::of(is_string($v) ? trim($v) : $v);
    }
}
