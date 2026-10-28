<?php

namespace App\Http\Resources;

use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Quantity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * PurchaseOrder (05 schemas, F8, F11): header with what the form edits, the supplier (live name, or the snapshot), lines
 * with what has arrived and what is still to come, the bills made from it, and totals (base currency; total_foreign
 * keeps the supplier's figure on a foreign order).
 */
class PurchaseOrderResource extends JsonResource
{
    /** `summary`: a list row — no lines and no bills. */
    public function __construct($resource, private readonly bool $summary = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $po = $this->resource;
        $currency = $po->currencyCode();

        return [
            'id' => $po->id,
            'number' => $po->number,
            'status' => $po->status,
            'party' => ['id' => $po->party_id, 'code' => $po->party?->code ?? ($po->party_snapshot['code'] ?? ''), 'name' => $po->party?->name ?? ($po->party_snapshot['name'] ?? '')],
            'order_date' => $po->order_date->toDateString(),
            'expected_date' => $po->expected_date?->toDateString(),
            'warehouse_id' => $po->warehouse_id,
            'payment_term_id' => $po->payment_term_id,
            'currency_code' => $currency,
            'exchange_rate' => (string) $po->exchange_rate,
            'source_note' => $po->source_note,
            'notes' => $po->notes,
            'internal_note' => $po->internal_note,
            // The order table has no sent_at column: the last e-mail to the supplier is in the audit log (purchase_order_sent).
            'sent_at' => $this->summary ? null : $this->sentAt($po->id),
            'lines' => ! $this->summary && $po->relationLoaded('lines') ? $po->lines->map(fn ($l) => [
                'id' => $l->id, 'line_no' => $l->line_no, 'product_id' => $l->product_id, 'product_code' => $l->product_code, 'product_name' => $l->product_name,
                'unit' => $l->unit_name, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_cost' => (string) $l->unit_cost,
                'discount_pct' => $l->discount_pct, 'discount_amount' => (string) $l->discount_amount, 'tax_rule_id' => $l->tax_rule_id, 'tax_rate' => $l->tax_rate,
                'tax_amount' => (string) $l->tax_amount, 'net_amount' => (string) $l->net_amount, 'line_total' => (string) $l->line_total,
                'received_quantity' => (string) $l->received_quantity,
                'remaining_quantity' => (string) $this->remaining($l->quantity, $l->received_quantity),
            ])->all() : [],
            'bills' => ! $this->summary && $po->relationLoaded('bills') ? $po->bills->map(fn ($b) => [
                'id' => $b->id, 'number' => $b->number, 'supplier_bill_no' => $b->supplier_bill_no, 'status' => $b->status, 'bill_date' => $b->bill_date->toDateString(),
            ])->all() : [],
            'total_foreign' => $po->total_foreign?->toString(),
            'subtotal' => (string) $po->subtotal,
            'discount_total' => (string) $po->discount_total,
            'tax_total' => (string) $po->tax_total,
            'rounding_adjustment' => (string) $po->rounding_adjustment,
            'total' => (string) $po->total,
            'amount_in_words' => AmountInWords::of($po->total_foreign ?? $po->total, $currency),
            'created_at' => $po->created_at->toIso8601String(),
            'created_by' => ! $this->summary && $po->createdBy ? ['id' => $po->createdBy->id, 'name' => $po->createdBy->name] : null,
            'updated_at' => $po->updated_at->toIso8601String(),
            'version' => (int) $po->lock_version,
        ];
    }

    private function sentAt(int $id): ?string
    {
        $at = DB::table('audit_logs')->where('auditable_type', 'purchase_orders')->where('auditable_id', $id)->where('action', 'purchase_order_sent')->max('created_at');

        return $at ? CarbonImmutable::parse($at, config('app.timezone'))->toIso8601String() : null;
    }

    private function remaining($ordered, $received): Quantity
    {
        $left = Quantity::of($ordered)->minus(Quantity::of($received));

        return $left->isNegative() ? Quantity::zero() : $left;
    }
}
