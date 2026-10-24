<?php

namespace App\Casts;

use App\Domain\Shared\DecimalValue;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Eloquent cast between a DECIMAL column and a value object (backlog P4.1-BE-01). Reads give the value object (or
 * null); writes accept the value object or a decimal string/int and store its fixed-scale string. MySQL returns
 * DECIMAL as strings, so no float is ever involved.
 */
abstract class DecimalCast implements CastsAttributes
{
    /** @return class-string<DecimalValue> */
    abstract protected function type(): string;

    public function get(Model $model, string $key, mixed $value, array $attributes): ?DecimalValue
    {
        return $value === null ? null : $this->type()::of((string) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! $value instanceof DecimalValue && ! is_string($value) && ! is_int($value)) {
            throw new InvalidArgumentException("{$key}: pass a value object or a decimal string, not ".get_debug_type($value));
        }

        return $this->type()::of($value)->toString();
    }
}
