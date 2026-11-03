<?php

namespace App\Domain\Documents;

use App\Domain\Shared\Quantity;
use App\Domain\Shared\Rate;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * The unit of each trade document line (BR-069, P10.7): the item's base unit (unit_id omitted or the base unit's id,
 * factor 1) or one of its extra units (product_units, 1 unit = factor base units). Quantity and price stay per that
 * unit; stock, average cost and cost of sales use quantity x factor in the base unit. Products must come with
 * `unit` and `units.unit` loaded.
 */
final class LineUnits
{
    /**
     * @param  Collection<int, Product>  $products  keyed by id
     * @param  array<string, list<string>>  $errors  gets lines.N.unit_id for a unit the item does not have
     * @return list<array{unit_id: int, name: string, factor: Rate}> per input line (base unit where the unit is refused)
     */
    public static function resolve(Collection $products, array $inLines, array &$errors): array
    {
        $out = [];
        foreach ($inLines as $i => $l) {
            $p = $products[$l['product_id']];
            $out[$i] = self::base($p);
            $unitId = isset($l['unit_id']) ? (int) $l['unit_id'] : null;
            if ($unitId === null || $unitId === (int) $p->unit_id) {
                continue;
            }
            $pu = $p->units->firstWhere('unit_id', $unitId);
            if ($pu === null) {
                $names = collect([$p->unit?->name])->merge($p->units->map(fn ($u) => $u->unit?->name))->filter()->implode(', ');
                $errors["lines.{$i}.unit_id"] = ["{$p->name} is bought and sold in {$names} only"];

                continue;
            }
            $out[$i] = ['unit_id' => $unitId, 'name' => (string) $pu->unit?->name, 'factor' => Rate::of((string) $pu->factor)];
        }

        return $out;
    }

    /** @return array{unit_id: int, name: string, factor: Rate} */
    public static function base(Product $p): array
    {
        return ['unit_id' => (int) $p->unit_id, 'name' => (string) $p->unit?->name, 'factor' => Rate::of(1)];
    }

    /** A line quantity in base units (stock quantities have 3 decimals, HALF_UP). */
    public static function baseQuantity(Quantity|string $quantity, Rate|string|null $factor): Quantity
    {
        return Quantity::of($quantity)->multipliedBy($factor ?? '1');
    }
}
