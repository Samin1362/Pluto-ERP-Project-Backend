<?php

namespace App\Http\Resources;

use App\Domain\Shared\Money;
use App\Domain\Shared\UnitCost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Product (05 schemas, D1–D3). Stock figures come from ProductStockQuery (`totals`, and `levels` on the detail
 * page). Without inventory.view_costs the cost fields avg_cost, stock_value, last_purchase_cost and each
 * warehouse's value are omitted (BR-052, TC-AUTH-010). Services carry no stock figures. `units` lists the base unit
 * and the extra units (BR-069, P10.7) with each unit's prices worked out here (price x factor), so the client never
 * multiplies.
 */
class ProductResource extends JsonResource
{
    public function __construct($resource, private readonly ?object $totals, private readonly bool $showCosts, private readonly ?array $levels = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $tracked = (bool) $this->track_stock;
        $row = [
            'id' => $this->id,
            'item_type' => $this->item_type,
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null,
            'unit' => $this->unit?->name,
            'unit_id' => $this->unit_id,
            'barcode' => $this->barcode,
            'selling_price' => $this->selling_price,
            'wholesale_price' => $this->wholesale_price,
            'tax_rule_id' => $this->tax_rule_id,
            'income_ledger_id' => $this->income_ledger_id,
            'track_stock' => $tracked,
            'stock_group' => $this->stockGroup ? ['id' => $this->stockGroup->id, 'name' => $this->stockGroup->name] : null,
            'preferred_supplier' => $this->preferredSupplier
                ? ['id' => $this->preferredSupplier->id, 'code' => $this->preferredSupplier->code, 'name' => $this->preferredSupplier->name] : null,
            'on_hand' => $tracked ? (string) ($this->totals->on_hand ?? '0.000') : null,
            'stock_status' => $tracked ? ($this->totals->stock_status ?? 'out_of_stock') : 'not_tracked',
        ];
        if ($this->showCosts) {
            $row['avg_cost'] = $tracked ? (string) ($this->totals->avg_cost ?? '0.0000') : null;
            $row['stock_value'] = $tracked ? (string) ($this->totals->stock_value ?? '0.00') : null;
            $row['last_purchase_cost'] = $this->last_purchase_cost;
        }
        if ($this->levels !== null) {
            $row['warehouses'] = $tracked ? array_map(fn ($l) => (new StockLevelResource($l, $this->showCosts))->resolve($request), $this->levels) : [];
        }

        $row['units'] = $this->unitRows();

        return $row + ['is_active' => (bool) $this->is_active, 'version' => (int) $this->lock_version];
    }

    /** Base unit first (factor 1), then the extra units by factor; prices per unit = the item's price x factor. */
    private function unitRows(): array
    {
        $rows = [['unit_id' => $this->unit_id, 'name' => $this->unit?->name, 'factor' => '1.000000', 'decimals' => (int) ($this->unit?->decimals ?? 0), 'is_base' => true]];
        foreach ($this->resource->units as $pu) {
            $rows[] = ['unit_id' => $pu->unit_id, 'name' => $pu->unit?->name, 'factor' => (string) $pu->factor, 'decimals' => (int) ($pu->unit?->decimals ?? 0), 'is_base' => false];
        }

        return array_map(function (array $u) {
            $u['selling_price'] = Money::of((string) $this->selling_price)->multipliedBy($u['factor'])->toString();
            $u['wholesale_price'] = $this->wholesale_price === null ? null : Money::of((string) $this->wholesale_price)->multipliedBy($u['factor'])->toString();
            if ($this->showCosts) {
                $u['last_purchase_cost'] = $this->last_purchase_cost === null ? null : UnitCost::of((string) $this->last_purchase_cost)->multipliedBy($u['factor'])->toString();
            }

            return $u;
        }, $rows);
    }
}
