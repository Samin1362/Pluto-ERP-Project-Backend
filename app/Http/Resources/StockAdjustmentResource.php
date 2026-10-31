<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * StockAdjustment (05 schemas; D10). Lines carry the product, the system quantity kept with the count, the counted
 * quantity and the signed difference; unit cost, value, net value and the posting preview only with
 * inventory.view_costs (BR-052). `summary`: a list row without lines.
 */
class StockAdjustmentResource extends JsonResource
{
    public function __construct($resource, private readonly bool $showCosts = false, private readonly bool $summary = false, private readonly ?array $preview = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $row = [
            'id' => $a->id, 'number' => $a->number, 'adjustment_date' => $a->adjustment_date->toDateString(), 'warehouse_id' => $a->warehouse_id,
            'warehouse_name' => $a->warehouse?->name, 'reason' => $a->reason, 'notes' => $a->notes, 'status' => $a->status,
            'lines' => $this->summary ? [] : $a->lines->map(fn ($l) => array_merge([
                'id' => $l->id, 'line_no' => $l->line_no, 'product_id' => $l->product_id, 'product_code' => $l->product?->code, 'product_name' => $l->product?->name,
                'unit' => $l->product?->unit?->name, 'system_qty' => (string) $l->system_qty, 'counted_qty' => (string) $l->counted_qty,
                'difference_qty' => (string) $l->difference_qty,
            ], $this->showCosts ? ['unit_cost' => (string) $l->unit_cost, 'value' => (string) $l->value] : []))->all(),
            'line_count' => $this->summary ? (int) ($a->lines_count ?? 0) : $a->lines->count(),
        ];
        if ($this->showCosts) {
            $row['net_value'] = (string) $a->net_value;
            if (! $this->summary) {
                $row['posting_preview'] = $this->preview ?? [];
            }
        }

        return $row + [
            'journal_entry_id' => $a->journal_entry_id, 'posted_at' => $a->posted_at?->toIso8601String(),
            'created_by' => ! $this->summary && $a->createdBy ? ['id' => $a->createdBy->id, 'name' => $a->createdBy->name] : null,
            'created_at' => $a->created_at?->toIso8601String(), 'version' => (int) $a->lock_version,
        ];
    }
}
