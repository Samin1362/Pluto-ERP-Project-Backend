<?php

namespace App\Http\Resources;

use App\Domain\Shared\AmountInWords;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * SalesInvoice (05 schemas, E3–E5): header, customer, lines with their calculated amounts, settlement, delivery
 * (challan, vehicle, time; GAP-12) and once posted the journal lines (PostingLine) of its entry. Line unit_cost and
 * cost_amount (COGS at posting) are null without inventory.view_costs (BR-052). Allocations arrive with receipts (P6).
 * `overdue` = posted, not fully paid, due date before today (06 §2).
 */
class SalesInvoiceResource extends JsonResource
{
    /** `summary`: a list row — no lines and no journal (one query per page, not per invoice). */
    public function __construct($resource, private readonly bool $showCosts = false, private readonly bool $summary = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $inv = $this->resource;
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $delivery = $inv->challan_no !== null || $inv->vehicle_no !== null || $inv->delivered_at !== null
            ? ['challan_no' => $inv->challan_no, 'vehicle_no' => $inv->vehicle_no, 'delivered_at' => $inv->delivered_at?->toIso8601String()] : null;

        return [
            'id' => $inv->id,
            'number' => $inv->number,
            'status' => $inv->status,
            'payment_status' => $inv->payment_status,
            'overdue' => $inv->status === 'posted' && $inv->balance_due->isPositive() && $inv->due_date->toDateString() < $today,
            'party' => ['id' => $inv->party_id, 'code' => $inv->party?->code ?? ($inv->party_snapshot['code'] ?? ''), 'name' => $inv->party?->name ?? ($inv->party_snapshot['name'] ?? '')],
            'invoice_date' => $inv->invoice_date->toDateString(),
            'due_date' => $inv->due_date->toDateString(),
            'warehouse_id' => $inv->warehouse_id,
            'customer_po_ref' => $inv->customer_po_ref,
            'lines' => ! $this->summary && $inv->relationLoaded('lines') ? $inv->lines->map(fn ($l) => [
                'id' => $l->id, 'line_no' => $l->line_no, 'product_id' => $l->product_id, 'product_code' => $l->product_code, 'product_name' => $l->product_name,
                'unit' => $l->unit_name, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_price' => (string) $l->unit_price,
                'discount_pct' => $l->discount_pct, 'discount_amount' => (string) $l->discount_amount, 'tax_rule_id' => $l->tax_rule_id, 'tax_rate' => $l->tax_rate,
                'tax_amount' => (string) $l->tax_amount, 'net_amount' => (string) $l->net_amount, 'line_total' => (string) $l->line_total,
                'unit_cost' => $this->showCosts ? $l->unit_cost?->toString() : null, 'cost_amount' => $this->showCosts ? $l->cost_amount?->toString() : null,
                'returned_quantity' => (string) $l->returned_quantity,
            ])->all() : [],
            'amount_settled' => (string) $inv->amount_settled,
            'balance_due' => (string) $inv->balance_due,
            'allocations' => [],
            'journal' => $this->summary ? [] : $this->journal($inv->journal_entry_id),
            'delivery' => $delivery,
            'posted_at' => $inv->posted_at?->toIso8601String(),
            'cancel_reason' => $inv->cancel_reason,
            'subtotal' => (string) $inv->subtotal,
            'discount_total' => (string) $inv->discount_total,
            'tax_total' => (string) $inv->tax_total,
            'rounding_adjustment' => (string) $inv->rounding_adjustment,
            'total' => (string) $inv->total,
            'amount_in_words' => AmountInWords::of($inv->total, 'BDT'),
            'created_at' => $inv->created_at->toIso8601String(),
            'created_by' => ! $this->summary && $inv->createdBy ? ['id' => $inv->createdBy->id, 'name' => $inv->createdBy->name] : null,
            'updated_at' => $inv->updated_at->toIso8601String(),
            'version' => (int) $inv->lock_version,
        ];
    }

    /** @return list<array{ledger_id: int, ledger_code: string, ledger_name: string, debit: string, credit: string}> */
    private function journal(?int $entryId): array
    {
        if (! $entryId) {
            return [];
        }

        return DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $entryId)->orderBy('l.line_no')
            ->get(['l.ledger_id', 'g.code as ledger_code', 'g.name as ledger_name', 'l.debit', 'l.credit'])
            ->map(fn ($r) => ['ledger_id' => $r->ledger_id, 'ledger_code' => $r->ledger_code, 'ledger_name' => $r->ledger_name, 'debit' => $r->debit, 'credit' => $r->credit])->all();
    }
}
