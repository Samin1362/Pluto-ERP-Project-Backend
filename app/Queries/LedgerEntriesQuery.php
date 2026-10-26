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
        $ledger = DB::table('ledgers')->where('id', $ledgerId)->first(['id', 'code', 'name']);
        $opening = Money::of((string) DB::table('journal_lines')->where('ledger_id', $ledgerId)->where('is_posted', 1)->where('entry_date', '<', $from)
            ->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b'));
        $rows = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.ledger_id', $ledgerId)->where('l.is_posted', 1)->whereBetween('l.entry_date', [$from, $to])
            ->orderBy('l.entry_date')->orderBy('e.id')->orderBy('l.line_no')
            ->get(['l.entry_date', 'e.id as entry_id', 'e.voucher_number', 'e.entry_type', 'e.narration', 'l.debit', 'l.credit', 'l.line_narration']);
        $others = DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->whereIn('l.journal_entry_id', $rows->pluck('entry_id')->unique())
            ->where('l.ledger_id', '!=', $ledgerId)->orderBy('l.line_no')->get(['l.journal_entry_id', 'g.name'])->groupBy('journal_entry_id');
        $balance = $opening;
        $lines = [];
        $monthly = [];
        $term = $narration !== null && $narration !== '' ? mb_strtolower($narration) : null;
        foreach ($rows as $r) {
            $balance = $balance->plus($r->debit)->minus($r->credit);
            $m = substr((string) $r->entry_date, 0, 7);
            $monthly[$m] ??= ['month' => $m, 'debit' => Money::zero(), 'credit' => Money::zero()];
            $monthly[$m]['debit'] = $monthly[$m]['debit']->plus($r->debit);
            $monthly[$m]['credit'] = $monthly[$m]['credit']->plus($r->credit);
            $monthly[$m]['closing'] = $balance;
            $text = $r->line_narration ?? $r->narration;
            if ($term !== null && ! str_contains(mb_strtolower((string) $text), $term)) {
                continue;
            }
            $names = ($others[$r->entry_id] ?? collect())->pluck('name')->unique()->values();
            $lines[] = ['date' => (string) $r->entry_date, 'voucher_id' => $r->entry_id, 'voucher_number' => $r->voucher_number, 'entry_type' => $r->entry_type,
                'particulars' => $names->take(2)->implode(', ').($names->count() > 2 ? ' and others' : ''), 'narration' => $text,
                'debit' => (string) Money::of($r->debit), 'credit' => (string) Money::of($r->credit), 'balance' => (string) $balance->abs(), 'balance_side' => $balance->isNegative() ? 'cr' : 'dr'];
        }
        $meta = null;
        if ($page !== null) {
            $total = count($lines);
            $meta = ['current_page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total, 'last_page' => max(1, (int) ceil($total / self::PER_PAGE))];
            $lines = array_slice($lines, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        }

        return [
            'ledger' => ['id' => $ledger->id, 'code' => $ledger->code, 'name' => $ledger->name],
            'period' => ['from' => $from, 'to' => $to],
            'opening' => (string) $opening->abs(), 'opening_side' => $opening->isNegative() ? 'cr' : 'dr',
            'lines' => $lines,
            'total_debit' => (string) Money::sum($rows->pluck('debit')), 'total_credit' => (string) Money::sum($rows->pluck('credit')),
            'closing' => (string) $balance->abs(), 'closing_side' => $balance->isNegative() ? 'cr' : 'dr',
            'monthly' => array_values(array_map(fn ($m) => ['month' => $m['month'], 'debit' => (string) $m['debit'], 'credit' => (string) $m['credit'],
                'closing' => (string) $m['closing']->abs(), 'closing_side' => $m['closing']->isNegative() ? 'cr' : 'dr'], $monthly)),
            'meta' => $meta,
        ];
    }
}
