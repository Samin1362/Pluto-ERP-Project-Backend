<?php

namespace App\Queries;

use App\Models\JournalEntry;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Read model for GET /customer-receipts (E14; backlog P6.1-BE-03): receipt entries (customer receipts are the receipt
 * vouchers with a payment_details row of a customer), filtered by customer, money account, date range, unapplied
 * (still has an advance) and q (number, customer, cheque or reference). Newest first.
 */
final class CustomerReceiptQuery
{
    public const SORTS = ['entry_date', '-entry_date', 'voucher_number', '-voucher_number', 'total_debit', '-total_debit'];

    public function page(array $f): LengthAwarePaginator
    {
        $q = JournalEntry::query()->select('journal_entries.*')->with('createdBy')
            ->join('payment_details as p', 'p.journal_entry_id', '=', 'journal_entries.id')->join('parties as y', 'y.id', '=', 'p.party_id')
            ->where('journal_entries.entry_type', 'receipt')->where('y.party_type', 'customer');
        if (isset($f['party_id'])) {
            $q->where('p.party_id', $f['party_id']);
        }
        if (isset($f['money_account_id'])) {
            $q->where('p.money_account_id', $f['money_account_id']);
        }
        if (isset($f['date_from'])) {
            $q->whereDate('journal_entries.entry_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('journal_entries.entry_date', '<=', $f['date_to']);
        }
        if (isset($f['unapplied'])) {
            $f['unapplied'] === 'true' ? $q->where('p.unapplied_amount', '>', 0)->where('journal_entries.status', 'posted') : $q->where('p.unapplied_amount', 0);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('journal_entries.voucher_number', 'like', $term)->orWhere('y.name', 'like', $term)
                ->orWhere('p.cheque_number', 'like', $term)->orWhere('p.transaction_reference', 'like', $term));
        }
        $sort = $f['sort'] ?? '-entry_date';

        return $q->orderBy('journal_entries.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('journal_entries.id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));
    }
}
