<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Warehouse (05 schemas, D7/D8): its facts plus stock figures from stock_balances (`stats`: value, products,
 * below_reorder). stock_value is omitted without inventory.view_costs (BR-052).
 */
class WarehouseResource extends JsonResource
{
    public function __construct($resource, private readonly ?object $stats, private readonly bool $showCosts)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $row = [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'branch_id' => $this->branch_id,
            'address' => $this->address,
            'manager' => $this->manager ? ['id' => $this->manager->id, 'name' => $this->manager->name] : null,
            'phone' => $this->phone,
            'inventory_ledger_id' => $this->inventory_ledger_id,
            'is_default_for_sales' => (bool) $this->is_default_for_sales,
            'status' => $this->status,
            'opens_on' => $this->opens_on?->toDateString(),
        ];
        if ($this->showCosts) {
            $row['stock_value'] = (string) ($this->stats->value ?? '0.00');
        }

        return $row + [
            'products_count' => (int) ($this->stats->products ?? 0),
            'below_reorder_count' => (int) ($this->stats->below_reorder ?? 0),
            'capacity_used_pct' => $this->capacity_used_pct,
            'version' => (int) $this->lock_version,
        ];
    }
}
