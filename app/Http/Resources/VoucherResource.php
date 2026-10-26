<?php

namespace App\Http\Resources;

use App\Domain\Approvals\ApprovalService;
use App\Domain\Shared\AmountInWords;
use App\Domain\Shared\Money;
use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Voucher (05 schema; E7/E14 receipts now, all vouchers later): header, lines with ledger code and name, and for a
 * receipt/payment its `payment` — party, account, method, cheque, bank charge, unapplied amount and the documents it
 * settles (live allocations). `summary` (list rows): no lines and no allocations, one query per page.
 */
class VoucherResource extends JsonResource
{
    public function __construct($resource, private readonly bool $summary = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        /** @var JournalEntry $e */
        $e = $this->resource;

        return [
            'id' => $e->id,
            'voucher_number' => $e->voucher_number,
            'entry_type' => $e->entry_type,
            'status' => $e->status,
            'entry_date' => $e->entry_date->toDateString(),
            'reference' => $e->reference,
            'narration' => $e->narration,
            'total_debit' => (string) $e->total_debit,
            'total_credit' => (string) $e->total_credit,
            'lines' => $this->summary ? [] : DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $e->id)
                ->orderBy('l.line_no')->get(['l.line_no', 'l.ledger_id', 'g.code', 'g.name', 'l.debit', 'l.credit', 'l.line_narration', 'l.against_reference'])
                ->map(fn ($l) => ['line_no' => $l->line_no, 'ledger_id' => $l->ledger_id, 'ledger_code' => $l->code, 'ledger_name' => $l->name, 'debit' => $l->debit,
                    'credit' => $l->credit, 'line_narration' => $l->line_narration, 'against_reference' => $l->against_reference])->all(),
            'payment' => $this->payment($e),
            'source' => $e->source_type ? ['type' => $e->source_type, 'id' => $e->source_id] : null,
            'reverses_entry_id' => $e->reverses_entry_id,
            'reversed_by_entry_id' => JournalEntry::where('reverses_entry_id', $e->id)->value('id'),
            'approval' => ApprovalService::latestFor($e),
            'audit_trail' => $this->summary ? [] : $this->auditTrail($e),
            'amount_in_words' => AmountInWords::of($e->total_debit, 'BDT'),
            'created_at' => $e->created_at->toIso8601String(),
            'created_by' => $e->createdBy ? ['id' => $e->createdBy->id, 'name' => $e->createdBy->name] : null,
            'updated_at' => $e->updated_at->toIso8601String(),
            'version' => (int) $e->lock_version,
        ];
    }

    /** AuditLog rows (05 schema) of this voucher, oldest first. */
    private function auditTrail(JournalEntry $e): array
    {
        return DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.auditable_type', 'journal_entries')->where('a.auditable_id', $e->id)
            ->orderBy('a.id')->get(['a.id', 'a.action', 'a.user_id', 'u.name', 'a.old_values', 'a.new_values', 'a.ip_address', 'a.created_at'])
            ->map(fn ($a) => ['id' => $a->id, 'action' => $a->action, 'user' => $a->user_id ? ['id' => $a->user_id, 'name' => $a->name] : null,
                'old_values' => $a->old_values ? json_decode($a->old_values, true) : null, 'new_values' => $a->new_values ? json_decode($a->new_values, true) : null,
                'ip_address' => $a->ip_address, 'created_at' => Carbon::parse($a->created_at)->toIso8601String()])->all();
    }

    private function payment(JournalEntry $e): ?array
    {
        $p = DB::table('payment_details as p')->join('money_accounts as m', 'm.id', '=', 'p.money_account_id')->leftJoin('parties as y', 'y.id', '=', 'p.party_id')
            ->where('p.journal_entry_id', $e->id)
            ->first(['p.*', 'm.name as account_name', 'm.account_type', 'y.code as party_code', 'y.name as party_name']);
        if (! $p) {
            return null;
        }
        $allocations = $this->summary ? [] : DB::table('bill_allocations as a')->join('journal_lines as l', 'l.id', '=', 'a.journal_line_id')
            ->leftJoin('sales_invoices as s', 's.id', '=', 'a.sales_invoice_id')->leftJoin('purchase_bills as b', 'b.id', '=', 'a.purchase_bill_id')
            ->where('l.journal_entry_id', $e->id)->whereNull('a.reversed_at')->orderBy('a.id')
            ->get(['a.id', 'a.amount', 'a.allocated_on', 'a.sales_invoice_id', 'a.purchase_bill_id', DB::raw('COALESCE(s.number, b.number) as number')])
            ->map(fn ($a) => ['id' => $a->id, 'document_type' => $a->sales_invoice_id ? 'sales_invoice' : 'purchase_bill',
                'document_id' => $a->sales_invoice_id ?? $a->purchase_bill_id, 'document_number' => $a->number, 'amount' => $a->amount, 'allocated_on' => $a->allocated_on])->all();

        return [
            'direction' => $p->direction,
            'party' => $p->party_id ? ['id' => $p->party_id, 'code' => $p->party_code, 'name' => $p->party_name] : null,
            'money_account' => ['id' => $p->money_account_id, 'name' => $p->account_name, 'account_type' => $p->account_type],
            'method' => $p->method,
            'cheque_number' => $p->cheque_number,
            'cheque_date' => $p->cheque_date,
            'drawn_on' => $p->drawn_on,
            'transaction_reference' => $p->transaction_reference,
            'amount' => $p->amount,
            'bank_charge' => $p->bank_charge,
            'net_received' => (string) Money::of($p->amount)->minus($p->bank_charge),
            'unapplied_amount' => $p->unapplied_amount,
            'allocations' => $allocations,
        ];
    }
}
