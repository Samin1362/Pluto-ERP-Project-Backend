<?php

namespace App\Queries;

use App\Domain\Shared\Money;
use Illuminate\Support\Facades\DB;

/**
 * LedgerStatement of one ledger (H7, party ledgers B6/C6; 05 getLedgersIdEntries; backlog P7.1-BE-03; BR-005): the
 * balance before `from`, the posted lines in [from, to] with a running balance, totals, the closing balance and one
 * row per month with its debits, credits and closing balance. `narration` narrows the lines shown, never the balances;
 * with `page` the lines come 100 at a time and `meta` is set.
 */
final class LedgerEntriesQuery
{
    public const PER_PAGE = 100;

    public function statement(int $ledgerId, string $from, string $to, ?string $narration = null, ?int $page = null): array
    {
        $ledger = DB::table('ledgers')->where('id', $ledgerId)->first(['id', 'code', 'name', 'company_id']);
        // company_id first: the covering index (company_id, ledger_id, is_posted, entry_date, debit, credit) then serves every
        // sum and the running balance without reading table rows (P17.1-BE-02).
        $cid = (int) $ledger->company_id;
        $posted = fn () => DB::table('journal_lines')->where('company_id', $cid)->where('ledger_id', $ledgerId)->where('is_posted', 1);
        $opening = Money::of((string) $posted()->where('entry_date', '<', $from)->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b'));

        // Months, totals and closing from grouped sums; the running balance per line from a window function in exact
        // DECIMAL arithmetic, so only the requested page of lines leaves the database (P17.1-BE-02: a busy ledger has
        // tens of thousands of lines a year).
        $months = $posted()->whereBetween('entry_date', [$from, $to])->groupByRaw("DATE_FORMAT(entry_date, '%Y-%m')")->orderByRaw('1')
            ->selectRaw("DATE_FORMAT(entry_date, '%Y-%m') m, CAST(SUM(debit) AS DECIMAL(18,2)) dr, CAST(SUM(credit) AS DECIMAL(18,2)) cr")->get();
        $balance = $opening;
        $monthly = [];
        $totalDr = $totalCr = Money::zero();
        foreach ($months as $m) {
            $balance = $balance->plus((string) $m->dr)->minus((string) $m->cr);
            $totalDr = $totalDr->plus((string) $m->dr);
            $totalCr = $totalCr->plus((string) $m->cr);
            $monthly[] = ['month' => $m->m, 'debit' => (string) Money::of((string) $m->dr), 'credit' => (string) Money::of((string) $m->cr),
                'closing' => (string) $balance->abs(), 'closing_side' => $balance->isNegative() ? 'cr' : 'dr'];
        }

        // The running balance needs only the lines (a line's journal_entry_id is its voucher's id, the order key); the
        // voucher columns are joined for the page's rows alone, or for every line only when narration filters them.
        $running = DB::table('journal_lines as l')->where('l.company_id', $cid)->where('l.ledger_id', $ledgerId)->where('l.is_posted', 1)->whereBetween('l.entry_date', [$from, $to])
            ->selectRaw('l.id AS line_id, l.entry_date, l.journal_entry_id AS entry_id, l.line_no, l.debit, l.credit,
                CAST(CAST(? AS DECIMAL(18,2)) + SUM(l.debit - l.credit) OVER (ORDER BY l.entry_date, l.journal_entry_id, l.line_no ROWS UNBOUNDED PRECEDING) AS DECIMAL(18,2)) AS run',
                [(string) $opening]);
        $q = DB::query()->fromSub($running, 't')->join('journal_lines as jl', 'jl.id', '=', 't.line_id')->join('journal_entries as e', 'e.id', '=', 't.entry_id')
            ->select(['t.entry_date', 't.entry_id', 'e.voucher_number', 'e.entry_type', DB::raw('COALESCE(jl.line_narration, e.narration) AS text'), 't.debit', 't.credit', 't.line_no', 't.run']);
        $filtered = $narration !== null && $narration !== '';
        if ($filtered) {
            $q->whereRaw('COALESCE(jl.line_narration, e.narration) LIKE ?', ['%'.addcslashes($narration, '%_\\').'%']);
        }
        $meta = null;
        if ($page !== null) {
            $total = $filtered ? (clone $q)->count() : $posted()->whereBetween('entry_date', [$from, $to])->count();
            $meta = ['current_page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total, 'last_page' => max(1, (int) ceil($total / self::PER_PAGE))];
            if (! $filtered) {
                // Page the lines before joining the vouchers.
                $q = DB::query()->fromSub(DB::query()->fromSub($running, 'w0')->orderBy('w0.entry_date')->orderBy('w0.entry_id')->orderBy('w0.line_no')
                    ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE), 't')
                    ->join('journal_lines as jl', 'jl.id', '=', 't.line_id')->join('journal_entries as e', 'e.id', '=', 't.entry_id')
                    ->select(['t.entry_date', 't.entry_id', 'e.voucher_number', 'e.entry_type', DB::raw('COALESCE(jl.line_narration, e.narration) AS text'), 't.debit', 't.credit', 't.line_no', 't.run']);
            } else {
                $q->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE);
            }
        }
        $rows = $q->orderBy('t.entry_date')->orderBy('t.entry_id')->orderBy('t.line_no')->get();
        $others = DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->whereIn('l.journal_entry_id', $rows->pluck('entry_id')->unique())
            ->where('l.ledger_id', '!=', $ledgerId)->orderBy('l.line_no')->get(['l.journal_entry_id', 'g.name'])->groupBy('journal_entry_id');
        $lines = [];
        foreach ($rows as $r) {
            $run = Money::of((string) $r->run);
            $names = ($others[$r->entry_id] ?? collect())->pluck('name')->unique()->values();
            $lines[] = ['date' => (string) $r->entry_date, 'voucher_id' => $r->entry_id, 'voucher_number' => $r->voucher_number, 'entry_type' => $r->entry_type,
                'particulars' => $names->take(2)->implode(', ').($names->count() > 2 ? ' and others' : ''), 'narration' => $r->text,
                'debit' => (string) Money::of($r->debit), 'credit' => (string) Money::of($r->credit), 'balance' => (string) $run->abs(), 'balance_side' => $run->isNegative() ? 'cr' : 'dr'];
        }

        return [
            'ledger' => ['id' => $ledger->id, 'code' => $ledger->code, 'name' => $ledger->name],
            'period' => ['from' => $from, 'to' => $to],
            'opening' => (string) $opening->abs(), 'opening_side' => $opening->isNegative() ? 'cr' : 'dr',
            'lines' => $lines,
            'total_debit' => (string) $totalDr, 'total_credit' => (string) $totalCr,
            'closing' => (string) $balance->abs(), 'closing_side' => $balance->isNegative() ? 'cr' : 'dr',
            'monthly' => $monthly,
            'meta' => $meta,
        ];
    }
}
