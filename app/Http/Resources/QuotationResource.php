<?php

namespace App\Http\Resources;

use App\Domain\Shared\AmountInWords;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Quotation (05 schemas, E10, E13): header with what the form edits (warehouse, payment term, notes), the customer
 * (live name, or the snapshot), lines as SalesLine (a quotation has no cost and nothing returned), totals, and the
 * invoice it was converted into.
 */
class QuotationResource extends JsonResource
{
    /** `summary`: a list row — no lines (one query per page, not per quotation). */
    public function __construct($resource, private readonly bool $summary = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $q = $this->resource;
        $invoice = $q->relationLoaded('invoice') ? $q->invoice : null;

        return [
            'id' => $q->id,
            'number' => $q->number,
            'status' => $q->status,
            'party' => ['id' => $q->party_id, 'code' => $q->party?->code ?? ($q->party_snapshot['code'] ?? ''), 'name' => $q->party?->name ?? ($q->party_snapshot['name'] ?? '')],
            'quotation_date' => $q->quotation_date->toDateString(),
            'valid_until' => $q->valid_until->toDateString(),
            'warehouse_id' => $q->warehouse_id,
            'payment_term_id' => $q->payment_term_id,
            'notes' => $q->notes,
            'internal_note' => $q->internal_note,
            'sent_at' => $q->sent_at?->toIso8601String(),
            'lines' => ! $this->summary && $q->relationLoaded('lines') ? $q->lines->map(fn ($l) => [
                'id' => $l->id, 'line_no' => $l->line_no, 'product_id' => $l->product_id, 'product_code' => $l->product_code, 'product_name' => $l->product_name,
                'unit' => $l->unit_name, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price,
                'discount_pct' => $l->discount_pct, 'discount_amount' => (string) $l->discount_amount, 'tax_rule_id' => $l->tax_rule_id, 'tax_rate' => $l->tax_rate,
                'tax_amount' => (string) $l->tax_amount, 'net_amount' => (string) $l->net_amount, 'line_total' => (string) $l->line_total,
                'unit_cost' => null, 'cost_amount' => null, 'returned_quantity' => '0.000',
            ])->all() : [],
            'converted_invoice_id' => $invoice?->id,
            'converted_invoice_number' => $invoice?->number,
            'subtotal' => (string) $q->subtotal,
            'discount_total' => (string) $q->discount_total,
            'tax_total' => (string) $q->tax_total,
            'rounding_adjustment' => (string) $q->rounding_adjustment,
            'total' => (string) $q->total,
            'amount_in_words' => AmountInWords::of($q->total, 'BDT'),
            'created_at' => $q->created_at->toIso8601String(),
            'created_by' => ! $this->summary && $q->createdBy ? ['id' => $q->createdBy->id, 'name' => $q->createdBy->name] : null,
            'updated_at' => $q->updated_at->toIso8601String(),
            'version' => (int) $q->lock_version,
        ];
    }
}
