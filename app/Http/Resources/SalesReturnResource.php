<?php

namespace App\Http\Resources;

use App\Models\SalesInvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Return (05 schemas) for a sales return (E8, E15): the invoice it is against, how the stock is handled, each returned
 * line with its invoice line, value and reason, and the credit note created on posting. Line unit_cost (the cost the
 * goods came back at) needs inventory.view_costs (BR-052).
 */
class SalesReturnResource extends JsonResource
{
    public function __construct($resource, private readonly bool $showCosts = false, private readonly bool $summary = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $snap = $r->party_snapshot ?? [];
        $note = $r->relationLoaded('creditNote') ? $r->creditNote : null;
        $invLines = ! $this->summary && $r->relationLoaded('lines') ? SalesInvoiceLine::whereIn('id', $r->lines->pluck('sales_invoice_line_id'))->get()->keyBy('id') : collect();

        return [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status,
            'party' => ['id' => $r->party_id, 'code' => $r->party?->code ?? ($snap['code'] ?? ''), 'name' => $r->party?->name ?? ($snap['name'] ?? '')],
            'source_document_id' => $r->sales_invoice_id,
            'source_number' => $r->invoice?->number ?? '',
            'return_date' => $r->return_date->toDateString(),
            'stock_action' => $r->stock_action,
            'warehouse_id' => $r->warehouse_id,
            'notes' => $r->notes,
            'usage' => $snap['note']['usage'] ?? null,
            'apply_to_document_id' => $snap['note']['apply_to_document_id'] ?? null,
            'posted_at' => $r->posted_at?->toIso8601String(),
            'cancel_reason' => $snap['cancel_reason'] ?? null,
            'lines' => $invLines->isEmpty() ? [] : $r->lines->map(fn ($l) => [
                'id' => $l->id, 'line_no' => $l->line_no, 'source_line_id' => $l->sales_invoice_line_id, 'product_id' => $l->product_id,
                'product_code' => $invLines[$l->sales_invoice_line_id]->product_code, 'product_name' => $invLines[$l->sales_invoice_line_id]->product_name,
                'unit' => $invLines[$l->sales_invoice_line_id]->unit_name, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price,
                'value' => (string) $l->value, 'reason' => $l->reason, 'unit_cost' => $this->showCosts ? $l->unit_cost?->toString() : null,
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
