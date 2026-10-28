<?php

namespace App\Http\Resources;

use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\AmountInWords;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

/**
 * PurchaseBill (05 schemas, F3/F4): header, supplier reference, lines with their calculated amounts, settlement,
 * and once posted the journal lines (PostingLine) of its entry. Allocations arrive with supplier payments (P8).
 * `overdue` = posted, not fully paid, due date before today (06 §2).
 */
class PurchaseBillResource extends JsonResource
{
    /** `summary`: a list row — no lines and no journal (one query per page, not per bill). */
    public function __construct($resource, private readonly bool $summary = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $b = $this->resource;
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();

        return [
            'id' => $b->id,
            'number' => $b->number,
            'supplier_bill_no' => $b->supplier_bill_no,
            'status' => $b->status,
            'payment_status' => $b->payment_status,
            'overdue' => $b->status === 'posted' && $b->balance_due->isPositive() && $b->due_date->toDateString() < $today,
            'party' => ['id' => $b->party_id, 'code' => $b->party?->code ?? ($b->party_snapshot['code'] ?? ''), 'name' => $b->party?->name ?? ($b->party_snapshot['name'] ?? '')],
            'bill_date' => $b->bill_date->toDateString(),
            'due_date' => $b->due_date->toDateString(),
            'warehouse_id' => $b->warehouse_id,
            'currency_code' => $b->currency_code,
            'exchange_rate' => (string) $b->exchange_rate,
            'total_foreign' => $b->total_foreign?->toString(),
            'purchase_order_id' => $b->purchase_order_id,
            'letter_of_credit_id' => $b->letter_of_credit_id,
            'lines' => ! $this->summary && $b->relationLoaded('lines') ? $b->lines->map(fn ($l) => [
                'id' => $l->id, 'line_no' => $l->line_no, 'product_id' => $l->product_id, 'product_code' => $l->product_code, 'product_name' => $l->product_name,
                'unit' => $l->unit_name, 'description' => $l->description, 'quantity' => (string) $l->quantity, 'unit_cost' => (string) $l->unit_cost,
                'discount_pct' => $l->discount_pct, 'discount_amount' => (string) $l->discount_amount, 'tax_rule_id' => $l->tax_rule_id, 'tax_rate' => $l->tax_rate,
                'tax_amount' => (string) $l->tax_amount, 'net_amount' => (string) $l->net_amount, 'line_total' => (string) $l->line_total,
                'purchase_order_line_id' => $l->purchase_order_line_id,
            ])->all() : [],
            'amount_settled' => (string) $b->amount_settled,
            'balance_due' => (string) $b->balance_due,
            'allocations' => $this->summary ? [] : AllocationService::forDocument('purchase_bill_id', $b->id),
            'journal' => $this->summary ? [] : $this->journal($b->journal_entry_id),
            'posted_at' => $b->posted_at?->toIso8601String(),
            'subtotal' => (string) $b->subtotal,
            'discount_total' => (string) $b->discount_total,
            'tax_total' => (string) $b->tax_total,
            'rounding_adjustment' => (string) $b->rounding_adjustment,
            'total' => (string) $b->total,
            'amount_in_words' => AmountInWords::of($b->total_foreign ?? $b->total, $b->currency_code),
            'created_at' => $b->created_at->toIso8601String(),
            'created_by' => ! $this->summary && $b->createdBy ? ['id' => $b->createdBy->id, 'name' => $b->createdBy->name] : null,
            'updated_at' => $b->updated_at->toIso8601String(),
            'version' => (int) $b->lock_version,
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
