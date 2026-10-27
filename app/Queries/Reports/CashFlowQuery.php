<?php

namespace App\Queries\Reports;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cash flow statement, indirect method (H18; 06 §17; backlog P9.1-BE-03): net profit for [from, to], plus non-cash
 * charges (expense lines of entries that credit a fixed-asset ledger without touching cash: depreciation), ± the change
 * in each working-capital group (operating), the fixed-asset groups (investing) and loans and capital (financing),
 * each ledger placed by the nearest `cash_flow_category` on its group path (unset: assets and liabilities operating,
 * equity financing). Year-end close entries are left out on both sides. Check: opening cash + net change = closing
 * balance of the cash and bank ledgers (category `cash`).
 *
 * Row: kind (heading|line|subtotal|total), key, label, group_id, depth, amount.
 */
final class CashFlowQuery
{
    private const SECTIONS = ['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities'];

    /** @var array<int, Money> investing group id => credits from non-cash charges in the range */
    private array $nonCashGroups = [];

    /** @var array<int, int> ledger id => account group id */
    private array $groupOf = [];

    public function report(int $companyId, string $from, string $to): array
    {
        $b = new LedgerBalances($companyId);
        $net = $b->net($from, $to, null, ['year_end_close']);
        $category = [];
        foreach ($b->ledgers as $id => $l) {
            $nature = $b->nature($id);
            $category[$id] = in_array($nature, ['income', 'expense'], true) ? 'pl'
                : ($b->inherited($id, 'cash_flow_category') ?? ($nature === 'equity' ? 'financing' : 'operating'));
        }
        $profit = Money::zero();
        $byGroup = []; // section => group id => cash effect (− change in the balance, Dr − Cr)
        foreach ($net as $id => $n) {
            match ($category[$id]) {
                'pl' => $profit = $profit->minus($n),
                'cash' => null,
                default => $byGroup[$category[$id]][$b->ledgers[$id]->account_group_id] = ($byGroup[$category[$id]][$b->ledgers[$id]->account_group_id] ?? Money::zero())->minus($n),
            };
        }
        $this->groupOf = array_map(fn ($l) => $l->account_group_id, $b->ledgers);
        $nonCash = $this->depreciation($companyId, $from, $to, $category);
        $rows = [];
        $sections = [];
        foreach (self::SECTIONS as $key => $title) {
            $rows[] = ['kind' => 'heading', 'key' => $key, 'label' => $title, 'depth' => 0];
            $total = Money::zero();
            if ($key === 'operating') {
                $rows[] = ['kind' => 'line', 'key' => 'net_profit', 'label' => 'Net profit', 'depth' => 1, 'amount' => (string) $profit];
                $rows[] = ['kind' => 'line', 'key' => 'depreciation', 'label' => 'Add: depreciation and other non-cash charges', 'depth' => 1, 'amount' => (string) $nonCash];
                $total = $profit->plus($nonCash);
            }
            foreach ($byGroup[$key] ?? [] as $gid => $amount) {
                if ($key === 'investing') {
                    $amount = $amount->minus($this->nonCashGroups[$gid] ?? Money::zero()); // depreciation is not an investing flow
                }
                if ($amount->isZero()) {
                    continue;
                }
                $g = $b->groups[$gid];
                $grew = $g->nature === 'asset' ? $amount->isNegative() : $amount->isPositive(); // the balance went up
                $label = $key === 'operating' ? ($grew ? 'Increase' : 'Decrease').' in '.mb_strtolower($g->name) : $g->name;
                $rows[] = ['kind' => 'line', 'key' => "group_{$gid}", 'label' => $label, 'group_id' => $gid, 'depth' => 1, 'amount' => (string) $amount];
                $total = $total->plus($amount);
            }
            $sections[$key] = $total;
            $rows[] = ['kind' => 'subtotal', 'key' => "{$key}_total", 'label' => "Net cash from {$title}", 'depth' => 0, 'amount' => (string) $total];
        }
        $change = Money::sum($sections);
        $cashIds = array_keys(array_filter($category, fn ($c) => $c === 'cash'));
        $opening = $this->cash($companyId, $cashIds, CarbonImmutable::parse($from)->subDay()->toDateString());
        $closing = $this->cash($companyId, $cashIds, $to);
        $rows[] = ['kind' => 'total', 'key' => 'net_change', 'label' => 'Net change in cash and bank', 'depth' => 0, 'amount' => (string) $change];
        $rows[] = ['kind' => 'line', 'key' => 'opening_cash', 'label' => 'Cash and bank at the start', 'depth' => 0, 'amount' => (string) $opening];
        $rows[] = ['kind' => 'total', 'key' => 'closing_cash', 'label' => 'Cash and bank at the end', 'depth' => 0, 'amount' => (string) $opening->plus($change)];
        $difference = $opening->plus($change)->minus($closing);

        return [
            'title' => 'Cash flow statement',
            'period' => ['from' => $from, 'to' => $to],
            'basis' => 'Indirect method · posted vouchers only',
            'columns' => [['key' => 'label', 'label' => 'Particulars', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount (৳)', 'type' => 'amount']],
            'rows' => $rows,
            'totals' => ['operating' => (string) $sections['operating'], 'investing' => (string) $sections['investing'], 'financing' => (string) $sections['financing'],
                'net_change' => (string) $change, 'opening_cash' => (string) $opening, 'closing_cash' => (string) $closing],
            'checks' => [['key' => 'cash_reconciled', 'ok' => $difference->isZero(), 'message' => $difference->isZero()
                ? 'Closing cash agrees with the cash and bank balance (৳ '.$closing.')' : 'Closing cash differs from the cash and bank balance by ৳ '.$difference->abs()]],
            'meta' => ['net_profit' => (string) $profit],
        ];
    }

    /**
     * Non-cash charges: the expense side of entries in the range that credit an investing ledger and touch no cash
     * ledger (depreciation, write-offs). Returns the total; nonCashGroups keeps, per investing group, the amount credited to it.
     *
     * @param  array<int, string>  $category
     */
    private function depreciation(int $companyId, string $from, string $to, array $category): Money
    {
        $this->nonCashGroups = [];
        $investing = array_keys(array_filter($category, fn ($c) => $c === 'investing'));
        if ($investing === []) {
            return Money::zero();
        }
        $entryIds = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.company_id', $companyId)->where('l.is_posted', 1)
            ->whereBetween('l.entry_date', [$from, $to])->where('e.entry_type', '!=', 'year_end_close')->whereIn('l.ledger_id', $investing)->where('l.credit', '>', 0)
            ->distinct()->pluck('l.journal_entry_id');
        $total = Money::zero();
        foreach (DB::table('journal_lines')->whereIn('journal_entry_id', $entryIds)->get(['journal_entry_id', 'ledger_id', 'debit', 'credit'])->groupBy('journal_entry_id') as $lines) {
            if ($lines->contains(fn ($l) => $category[$l->ledger_id] === 'cash')) {
                continue;
            }
            $charge = Money::sum($lines->filter(fn ($l) => $category[$l->ledger_id] === 'pl')->map(fn ($l) => Money::of($l->debit)->minus($l->credit)));
            if ($charge->isZero()) {
                continue;
            }
            $total = $total->plus($charge);
            foreach ($lines->filter(fn ($l) => $category[$l->ledger_id] === 'investing') as $l) {
                $gid = $this->groupOf[$l->ledger_id];
                $this->nonCashGroups[$gid] = ($this->nonCashGroups[$gid] ?? Money::zero())->plus(Money::of($l->credit)->minus($l->debit));
            }
        }

        return $total;
    }

    /** @param list<int> $ledgerIds */
    private function cash(int $companyId, array $ledgerIds, string $to): Money
    {
        return Money::of((string) DB::table('journal_lines')->where('company_id', $companyId)->where('is_posted', 1)->whereIn('ledger_id', $ledgerIds ?: [0])
            ->where('entry_date', '<=', $to)
            ->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b'));
    }
}
