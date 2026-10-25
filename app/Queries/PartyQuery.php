<?php

namespace App\Queries;

use App\Models\Party;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read model for parties (03 §3 Queries/): adds balance, overdue and opening amounts as SQL subqueries so lists
 * can filter and sort by balance without N+1 queries. All amounts stay DECIMAL in SQL and strings in PHP.
 */
final class PartyQuery
{
    private \Illuminate\Database\Query\Builder $balanceSub;

    public function __construct(private readonly CurrentCompany $current) {}

    public function base(): Builder
    {
        $cid = $this->current->id();
        $balance = DB::table('journal_lines')->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2))')
            ->whereColumn('journal_lines.ledger_id', 'parties.ledger_id')->where('journal_lines.company_id', $cid)->where('journal_lines.is_posted', true);
        // Locks the opening balance (BR-028): any entry on the ledger besides the party's own opening entries.
        $entries = DB::table('journal_lines')->join('journal_entries as je', 'je.id', '=', 'journal_lines.journal_entry_id')->selectRaw('COUNT(*) > 0')
            ->whereColumn('journal_lines.ledger_id', 'parties.ledger_id')->where('journal_lines.company_id', $cid)
            ->where(fn ($w) => $w->whereNotIn('je.entry_type', ['opening_balance', 'reversal'])->orWhere('je.source_type', '!=', 'parties')->orWhereNull('je.source_type'));
        $opening = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->selectRaw('CAST(COALESCE(SUM(journal_lines.debit) - SUM(journal_lines.credit), 0) AS DECIMAL(18,2))')
            ->whereColumn('journal_lines.ledger_id', 'parties.ledger_id')->where('journal_lines.company_id', $cid)
            ->where('journal_entries.entry_type', 'opening_balance')->where('journal_entries.status', 'posted');
        $today = now('Asia/Dhaka')->toDateString();
        $overdueSales = DB::table('sales_invoices')->selectRaw('CAST(COALESCE(SUM(balance_due), 0) AS DECIMAL(18,2))')
            ->whereColumn('sales_invoices.party_id', 'parties.id')->where('sales_invoices.company_id', $cid)
            ->where('sales_invoices.status', 'posted')->where('sales_invoices.due_date', '<', $today);
        $overdueBills = DB::table('purchase_bills')->selectRaw('CAST(COALESCE(SUM(balance_due), 0) AS DECIMAL(18,2))')
            ->whereColumn('purchase_bills.party_id', 'parties.id')->where('purchase_bills.company_id', $cid)
            ->where('purchase_bills.status', 'posted')->where('purchase_bills.due_date', '<', $today);

        $this->balanceSub = $balance;

        return Party::query()->select('parties.*')
            ->selectSub($balance, 'balance_net')
            ->selectSub($entries, 'has_entries')
            ->selectSub($opening, 'opening_amount')
            ->selectRaw("CASE WHEN parties.party_type = 'customer' THEN (".$overdueSales->toSql().') ELSE ('.$overdueBills->toSql().') END AS overdue_amount',
                array_merge($overdueSales->getBindings(), $overdueBills->getBindings()))
            ->with(['group', 'paymentTerm', 'ledger']);
    }

    public function list(array $f): Builder
    {
        $q = $this->base()->where('parties.party_type', $f['type']);
        if (isset($f['status'])) {
            $q->where('parties.status', $f['status']);
        }
        if (isset($f['group_id'])) {
            $q->where('parties.party_group_id', $f['group_id']);
        }
        if (isset($f['area'])) {
            $q->where('parties.area', $f['area']);
        }
        if (isset($f['country_code'])) {
            $q->where('parties.country_code', strtoupper($f['country_code']));
        }
        if (isset($f['q']) && $f['q'] !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('parties.name', 'like', $term)->orWhere('parties.code', 'like', $term)
                ->orWhere('parties.mobile', 'like', $term)->orWhere('parties.contact_person', 'like', $term));
        }
        if (isset($f['balance'])) {
            // Portable (no HAVING without GROUP BY): compare the same correlated subquery in WHERE.
            $q->whereRaw('('.$this->balanceSub->toSql().') '.($f['balance'] === 'outstanding' ? '<>' : '=').' 0', $this->balanceSub->getBindings());
        }
        $sort = $f['sort'] ?? 'name';
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-');
        // balance_net is debit - credit: a supplier's payable is negative, so "balance, high to low" runs the other way.
        if ($col === 'balance' && $f['type'] === 'supplier') {
            $dir = $dir === 'asc' ? 'desc' : 'asc';
        }
        $q->orderBy($col === 'balance' ? 'balance_net' : 'parties.'.$col, $dir)->orderBy('parties.id');

        return $q;
    }
}
