<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** StockLevel (05 schemas; D4 by warehouse, D8 by product). `value` only with inventory.view_costs (BR-052). */
class StockLevelResource extends JsonResource
{
    public function __construct($resource, private readonly bool $showCosts)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $l = $this->resource;
        $row = [
            'product_id' => $l->product_id, 'product_code' => $l->product_code, 'product_name' => $l->product_name,
            'warehouse_id' => $l->warehouse_id, 'warehouse_name' => $l->warehouse_name, 'qty_on_hand' => (string) $l->qty_on_hand,
            'reorder_level' => (string) $l->reorder_level, 'reorder_qty' => (string) $l->reorder_qty,
        ];
        if ($this->showCosts) {
            $row['value'] = (string) $l->value;
        }

        return $row + ['status' => $l->status];
    }
}
