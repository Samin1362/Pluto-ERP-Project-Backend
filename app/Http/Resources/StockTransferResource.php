<?php

namespace App\Http\Resources;

use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * StockTransfer (05 schemas; D11). Lines: product, quantity sent, quantity received (once received) and, with
 * inventory.view_costs (BR-052), the unit cost frozen at dispatch; value_at_cost and transit_loss_value likewise.
 * `available` (a draft's source quantity on hand per product) helps D11 warn before dispatch. `summary`: a list row.
 */
class StockTransferResource extends JsonResource
{
    /** @param  array<int, string>  $available  product_id → quantity on hand at the source (drafts) */
    public function __construct($resource, private readonly bool $showCosts = false, private readonly bool $summary = false,
        private readonly ?Money $value = null, private readonly ?Money $loss = null, private readonly array $available = [])
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $t = $this->resource;
        $row = [
            'id' => $t->id, 'number' => $t->number, 'from_warehouse_id' => $t->from_warehouse_id, 'to_warehouse_id' => $t->to_warehouse_id,
            'from_warehouse_name' => $t->from?->name, 'to_warehouse_name' => $t->to?->name, 'status' => $t->status,
            'dispatch_date' => $t->dispatch_date?->toDateString(), 'received_date' => $t->received_date?->toDateString(),
            'vehicle' => $t->vehicle, 'driver' => $t->driver, 'notes' => $t->notes,
            'lines' => $this->summary ? [] : $t->lines->map(fn ($l) => array_merge([
                'id' => $l->id, 'line_no' => $l->line_no, 'product_id' => $l->product_id, 'product_code' => $l->product?->code, 'product_name' => $l->product?->name,
                'unit' => $l->product?->unit?->name, 'quantity' => (string) $l->quantity, 'received_quantity' => $l->received_quantity !== null ? (string) $l->received_quantity : null,
            ], $t->status === 'draft' ? ['available' => (string) ($this->available[$l->product_id] ?? '0.000')] : [],
                $this->showCosts ? ['unit_cost' => $l->unit_cost !== null ? (string) $l->unit_cost : null] : []))->all(),
            'line_count' => $t->lines->count(),
            'total_units' => (string) Quantity::sum($t->lines->map(fn ($l) => Quantity::of($l->quantity))),
        ];
        if ($this->showCosts) {
            if ($this->value !== null) {
                $row['value_at_cost'] = (string) $this->value;
            }
            if ($this->loss !== null) {
                $row['transit_loss_value'] = (string) $this->loss;
            }
        }

        return $row + [
            'dispatch_entry_id' => $t->dispatch_entry_id, 'receive_entry_id' => $t->receive_entry_id,
            'created_by' => ! $this->summary && $t->createdBy ? ['id' => $t->createdBy->id, 'name' => $t->createdBy->name] : null,
            'created_at' => $t->created_at?->toIso8601String(), 'version' => (int) $t->lock_version,
        ];
    }
}
