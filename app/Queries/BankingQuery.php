<?php

namespace App\Queries;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Cash and bank reads (G1, G13; 06 §17; BR-005; backlog P12.1-BE-01). Only posted lines count. A money account is its
 * ledger: what moved through it is the ledger's journal lines. Direction: a contra (transfer between money accounts) is
 * `transfer`; otherwise money in is a debit on the account, money out a credit.
 */
final class BankingQuery
{
    private const TYPE_ORDER = "FIELD(a.account_type, 'cash', 'bank', 'wallet', 'card', 'cash_credit')";

    /** G1: per account its balance and share, totals by type, this month's in/out, a 30-day combined balance series, recent moves, reconciliation status. */
    public function overview(int $c): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka');
        $accounts = DB::table('money_accounts as a')->where('a.company_id', $c)->where('a.is_active', true)
            ->leftJoin('journal_lines as jl', fn ($j) => $j->on('jl.ledger_id', '=', 'a.ledger_id')->where('jl.is_posted', '=', 1))
            ->groupBy('a.id', 'a.name', 'a.account_type', 'a.bank_name', 'a.account_number', 'a.overdraft_limit', 'a.last_reconciled_on')
            ->orderByRaw(self::TYPE_ORDER)->orderBy('a.name')
            ->selectRaw('a.id, a.name, a.account_type, a.bank_name, a.account_number, a.overdraft_limit, a.last_reconciled_on,
                CAST(COALESCE(SUM(jl.debit) - SUM(jl.credit), 0) AS DECIMAL(18,2)) as balance')->get();
        $total = Money::sum($accounts->map(fn ($a) => Money::of((string) $a->balance)));
        $positive = Money::sum($accounts->map(fn ($a) => Money::of((string) $a->balance))->filter(fn ($m) => $m->isPositive()));
        $unmatched = DB::table('bank_statement_lines')->where('company_id', $c)->where('status', 'unmatched')->groupBy('money_account_id')
            ->selectRaw('money_account_id, COUNT(*) n')->pluck('n', 'money_account_id');
        $rows = $accounts->map(fn ($a) => [
            'kind' => 'account', 'id' => $a->id, 'name' => $a->name, 'account_type' => $a->account_type, 'bank_name' => $a->bank_name,
            'account_number' => $a->account_number ? '•••'.substr($a->account_number, -4) : null, 'balance' => (string) Money::of((string) $a->balance),
            'available' => (string) Money::of((string) $a->balance)->plus((string) ($a->overdraft_limit ?? '0')),
            'share' => $positive->isPositive() && Money::of((string) $a->balance)->isPositive() ? (string) Money::of((string) $a->balance)->multipliedBy(100)->dividedBy($positive, 1) : '0.0',
            'last_reconciled_on' => $a->last_reconciled_on, 'unmatched_lines' => (int) ($unmatched[$a->id] ?? 0),
        ])->all();
        $byType = fn (array $types) => (string) Money::sum($accounts->filter(fn ($a) => in_array($a->account_type, $types, true))->map(fn ($a) => Money::of((string) $a->balance)));

        $ledgers = $accounts->isEmpty() ? [0] : DB::table('money_accounts')->whereIn('id', $accounts->pluck('id'))->pluck('ledger_id')->all();
        $month = $this->lines($c, $ledgers)->whereBetween('jl.entry_date', [$today->startOfMonth()->toDateString(), $today->toDateString()])
            ->where('je.entry_type', '!=', 'contra')->selectRaw('COALESCE(SUM(jl.debit), 0) as i, COALESCE(SUM(jl.credit), 0) as o')->first();

        // 30 days: the combined balance at the end of each day.
        $start = $today->subDays(29);
        $before = Money::of((string) $this->lines($c, $ledgers)->where('jl.entry_date', '<', $start->toDateString())->selectRaw('COALESCE(SUM(jl.debit) - SUM(jl.credit), 0) as b')->value('b'));
        $daily = $this->lines($c, $ledgers)->whereBetween('jl.entry_date', [$start->toDateString(), $today->toDateString()])->groupBy('jl.entry_date')
            ->selectRaw('jl.entry_date as d, SUM(jl.debit) - SUM(jl.credit) as net')->pluck('net', 'd');
        $series = [];
        $run = $before;
        for ($d = $start; $d <= $today; $d = $d->addDay()) {
            $run = $run->plus((string) ($daily[$d->toDateString()] ?? '0'));
            $series[] = ['date' => $d->toDateString(), 'balance' => (string) $run];
        }

        return [
            'title' => 'Cash and bank', 'period' => ['as_of' => $today->toDateString()], 'basis' => 'Posted vouchers only',
            'columns' => [['key' => 'name', 'label' => 'Account', 'type' => 'text'], ['key' => 'balance', 'label' => 'Balance (৳)', 'type' => 'amount'],
                ['key' => 'share', 'label' => 'Share', 'type' => 'pct']],
            'rows' => $rows,
            'totals' => ['total' => (string) $total, 'cash' => $byType(['cash']), 'bank' => $byType(['bank', 'cash_credit']), 'wallet' => $byType(['wallet', 'card']),
                'in_this_month' => (string) Money::of((string) $month->i), 'out_this_month' => (string) Money::of((string) $month->o), 'accounts' => count($rows),
                'unmatched_lines' => (int) $unmatched->sum()],
            'meta' => ['series' => $series, 'recent' => $this->transactions($c, ['per_page' => 8])->items()],
        ];
    }

    /**
     * G13: posted lines on money accounts, newest first. One row per voucher line on a money account; a contra shows on
     * both accounts it touches, as `transfer`.
     *
     * @param  array{q?: string, date_from?: string, date_to?: string, direction?: string, money_account_id?: int, sort?: string, page?: int, per_page?: int}  $f
     */
    public function transactions(int $c, array $f): LengthAwarePaginator
    {
        $q = DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->join('money_accounts as a', 'a.ledger_id', '=', 'jl.ledger_id')
            ->leftJoin('payment_details as pd', 'pd.journal_entry_id', '=', 'je.id')->leftJoin('parties as p', 'p.id', '=', 'pd.party_id')
            ->where('je.company_id', $c)->where('jl.is_posted', 1)
            ->selectRaw("jl.entry_date as date, je.id as voucher_id, je.voucher_number, je.entry_type, a.id as money_account_id, a.name as account,
                COALESCE(p.name, NULLIF(pd.payee_text, ''), NULLIF(jl.line_narration, ''), NULLIF(je.narration, ''), je.voucher_number) as particulars,
                CASE WHEN je.entry_type = 'contra' THEN 'transfer' WHEN jl.debit > 0 THEN 'in' ELSE 'out' END as direction,
                jl.debit as amount_in, jl.credit as amount_out");
        if (! empty($f['money_account_id'])) {
            $q->where('a.id', $f['money_account_id']);
        }
        if (! empty($f['date_from'])) {
            $q->where('jl.entry_date', '>=', $f['date_from']);
        }
        if (! empty($f['date_to'])) {
            $q->where('jl.entry_date', '<=', $f['date_to']);
        }
        match ($f['direction'] ?? null) {
            'transfer' => $q->where('je.entry_type', 'contra'),
            'in' => $q->where('je.entry_type', '!=', 'contra')->where('jl.debit', '>', 0),
            'out' => $q->where('je.entry_type', '!=', 'contra')->where('jl.credit', '>', 0),
            default => null,
        };
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('je.voucher_number', 'like', $term)->orWhere('je.narration', 'like', $term)->orWhere('jl.line_narration', 'like', $term)
                ->orWhere('p.name', 'like', $term)->orWhere('pd.payee_text', 'like', $term)->orWhere('je.reference', 'like', $term));
        }
        $desc = ($f['sort'] ?? '-date') !== 'date';
        $page = $q->orderBy('jl.entry_date', $desc ? 'desc' : 'asc')->orderBy('jl.id', $desc ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));
        $page->setCollection($page->getCollection()->map(fn ($r) => ['date' => (string) $r->date, 'voucher_id' => $r->voucher_id, 'voucher_number' => $r->voucher_number,
            'account' => $r->account, 'particulars' => $r->particulars, 'direction' => $r->direction,
            'amount_in' => (string) Money::of((string) $r->amount_in), 'amount_out' => (string) Money::of((string) $r->amount_out)]));

        return $page;
    }

    private function lines(int $c, array $ledgerIds)
    {
        return DB::table('journal_lines as jl')->join('journal_entries as je', 'je.id', '=', 'jl.journal_entry_id')
            ->where('je.company_id', $c)->where('jl.is_posted', 1)->whereIn('jl.ledger_id', $ledgerIds);
    }
}
