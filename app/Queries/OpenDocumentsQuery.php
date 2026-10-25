<?php

namespace App\Queries;

use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\Money;
use App\Models\Party;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * OpenDocuments of a party (05 getPartiesIdOpenDocuments; R-08; backlog P6.1-BE-02): its posted invoices (customer)
 * or bills (supplier) with money still due, oldest due first, and its open credits — settling ledger lines with an
 * unapplied part (receipts, payments, notes, advances, opening credits).
 */
final class OpenDocumentsQuery
{
    public function for(Party $party): array
    {
        $table = $party->party_type === 'customer' ? 'sales_invoices' : 'purchase_bills';
        $dateCol = $party->party_type === 'customer' ? 'invoice_date' : 'bill_date';
        $today = CarbonImmutable::parse(CarbonImmutable::now('Asia/Dhaka')->toDateString()); // plain dates, same zone as due_date
        $docs = DB::table($table)->where('company_id', $party->company_id)->where('party_id', $party->id)->where('status', 'posted')->where('balance_due', '>', 0)
            ->orderBy('due_date')->orderBy($dateCol)->orderBy('id')->get(['id', 'number', $dateCol.' as date', 'due_date', 'total', 'amount_settled', 'balance_due'])
            ->map(fn ($d) => ['id' => $d->id, 'type' => $party->party_type === 'customer' ? 'sales_invoice' : 'purchase_bill', 'number' => $d->number,
                'date' => $d->date, 'due_date' => $d->due_date, 'total' => $d->total, 'settled' => $d->amount_settled, 'balance' => $d->balance_due,
                'days_overdue' => max(0, (int) CarbonImmutable::parse($d->due_date)->diffInDays($today, false))])->all();

        $side = $party->party_type === 'customer' ? 'credit' : 'debit';
        $credits = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.ledger_id', $party->ledger_id)->where('l.is_posted', 1)->where('e.status', 'posted')->where("l.{$side}", '>', 0)
            ->whereIn('e.entry_type', AllocationService::SETTLING_TYPES)
            ->leftJoinSub(DB::table('bill_allocations')->whereNull('reversed_at')->groupBy('journal_line_id')->selectRaw('journal_line_id, SUM(amount) used'), 'a', 'a.journal_line_id', '=', 'l.id')
            ->orderBy('e.entry_date')->orderBy('l.id')
            ->get(['l.id', 'e.voucher_number', 'e.entry_type', 'e.entry_date', "l.{$side} as amount", DB::raw('COALESCE(a.used, 0) as used')])
            ->map(fn ($c) => ['journal_line_id' => $c->id, 'kind' => AllocationService::kind($c->entry_type), 'number' => $c->voucher_number,
                'date' => $c->entry_date, 'amount' => $c->amount, 'unapplied' => (string) Money::of($c->amount)->minus((string) $c->used)])
            ->filter(fn ($c) => Money::of($c['unapplied'])->isPositive())->values()->all();

        return [
            'party' => ['id' => $party->id, 'code' => $party->code, 'name' => $party->name],
            'documents' => $docs,
            'credits' => $credits,
            'total_open' => (string) Money::sum(array_map(fn ($d) => Money::of($d['balance']), $docs)),
            'unapplied_credit' => (string) Money::sum(array_map(fn ($c) => Money::of($c['unapplied']), $credits)),
        ];
    }
}
