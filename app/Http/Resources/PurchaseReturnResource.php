<?php

namespace App\Http\Resources;

use App\Models\PurchaseBillLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Return (05 schemas) for a purchase return (F6, F13): the bill it is against, how the stock is handled, each returned
 * line with its bill line, cost, value (with recoverable tax) and reason, and its debit note. Line unit_cost needs
 * inventory.view_costs (BR-052); value is the money owed back and is always shown.
 */
class PurchaseReturnResource extends JsonResource
{
    public function __construct($resource, private readonly bool $showCosts = false, private readonly bool $summary = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $snap = $r->party_snapshot ?? [];
        $note = $r->relationLoaded('debitNote') ? $r->debitNote : null;
        $billLines = ! $this->summary && $r->relationLoaded('lines') ? PurchaseBillLine::whereIn('id', $r->lines->pluck('purchase_bill_line_id'))->get()->keyBy('id') : collect();

        return [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status,
            'party' => ['id' => $r->party_id, 'code' => $r->party?->code ?? ($snap['code'] ?? ''), 'name' => $r->party?->name ?? ($snap['name'] ?? '')],
            'source_document_id' => $r->purchase_bill_id,
            'source_number' => $r->bill?->number ?? '',
            'return_date' => $r->return_date->toDateString(),
            'stock_action' => $r->stock_action,
            'warehouse_id' => $r->warehouse_id,
            'notes' => $r->notes,
            'usage' => $snap['note']['usage'] ?? null,
            'apply_to_document_id' => $snap['note']['apply_to_document_id'] ?? null,
            'posted_at' => $r->posted_at?->toIso8601String(),
            'cancel_reason' => $snap['cancel_reason'] ?? null,
            'lines' => $billLines->isEmpty() ? [] : $r->lines->map(fn ($l) => [
                'id' => $l->id, 'line_no' => $l->line_no, 'source_line_id' => $l->purchase_bill_line_id, 'product_id' => $l->product_id,
                'product_code' => $billLines[$l->purchase_bill_line_id]->product_code, 'product_name' => $billLines[$l->purchase_bill_line_id]->product_name,
                'unit' => $billLines[$l->purchase_bill_line_id]->unit_name, 'quantity' => (string) $l->quantity, 'value' => (string) $l->value, 'reason' => $l->reason,
                'unit_cost' => $this->showCosts ? (string) $l->unit_cost : null,
            ])->all(),
            'total' => (string) $r->total,
            'note_id' => $note?->id,
            'note_number' => $note?->number,
            'note_status' => $note?->status,
            'created_at' => $r->created_at?->toIso8601String(),
            'created_by' => ! $this->summary && $r->createdBy ? ['id' => $r->createdBy->id, 'name' => $r->createdBy->name] : null,
            'version' => (int) $r->lock_version,
        ];
    }
}
