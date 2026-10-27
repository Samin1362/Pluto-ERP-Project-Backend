<?php

namespace App\Queries\Reports;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;

/**
 * Profit and loss (H16; 06 §17; backlog P9.1-BE-02; FR-076): income and expense ledgers in [from, to], grouped as
 * Revenue (41, sales returns and discounts inside it as negatives), Cost of sales (5), Gross profit, Operating expenses
 * (61 and other expense groups), Operating profit, Other income (42 and other income groups), Depreciation and finance
 * (62), Profit before tax, Net profit. No income-tax rules exist in v1, so there is no tax line and net profit equals
 * profit before tax. `compare=prior` adds the same dates one year earlier; `detail=totals` drops the ledger rows.
 * Year-end close entries are left out, so a closed year still shows its profit.
 *
 * Row: kind (heading|ledger|subtotal|total), key, label, code, ledger_id, depth, amount, prior.
 */
final class ProfitAndLossQuery
{
    private const SECTIONS = [
        'revenue' => 'Revenue', 'cost_of_sales' => 'Cost of sales', 'operating_expenses' => 'Operating expenses',
        'other_income' => 'Other income', 'finance' => 'Depreciation and finance',
    ];

    public function report(int $companyId, string $from, string $to, bool $compare = false, bool $detail = true, ?int $branchId = null): array
    {
        $b = new LedgerBalances($companyId);
        $now = $this->sections($b, $b->net($from, $to, $branchId, ['year_end_close']));
        $prior = null;
        $period = ['from' => $from, 'to' => $to];
        if ($compare) {
            [$pf, $pt] = [CarbonImmutable::parse($from)->subYear()->toDateString(), CarbonImmutable::parse($to)->subYear()->toDateString()];
            $prior = $this->sections($b, $b->net($pf, $pt, $branchId, ['year_end_close']));
            $period['prior_from'] = $pf;
            $period['prior_to'] = $pt;
        }
        $rows = [];
        $line = fn (string $kind, string $key, string $label, Money $amount, ?Money $p, int $depth = 0, array $extra = []) => [
            'kind' => $kind, 'key' => $key, 'label' => $label, 'depth' => $depth, ...$extra, 'amount' => (string) $amount, ...($compare ? ['prior' => (string) ($p ?? Money::zero())] : []),
        ];
        $section = function (string $key) use (&$rows, $now, $prior, $line, $detail, $compare): void {
            $rows[] = ['kind' => 'heading', 'key' => $key, 'label' => self::SECTIONS[$key], 'depth' => 0];
            if ($detail) {
                $ids = array_unique([...array_keys($now['ledgers'][$key]), ...($compare ? array_keys($prior['ledgers'][$key]) : [])]);
                $ledgers = array_map(fn ($id) => $now['info'][$id] ?? $prior['info'][$id], $ids);
                usort($ledgers, fn ($x, $y) => strcmp($x->code, $y->code));
                foreach ($ledgers as $l) {
                    $rows[] = $line('ledger', "ledger_{$l->id}", $l->name, $now['ledgers'][$key][$l->id] ?? Money::zero(), $prior['ledgers'][$key][$l->id] ?? null, 1,
                        ['code' => $l->code, 'ledger_id' => $l->id]);
                }
            }
            $rows[] = $line('subtotal', "{$key}_total", 'Total '.mb_strtolower(self::SECTIONS[$key]), $now['totals'][$key], $prior['totals'][$key] ?? null);
        };
        $section('revenue');
        $section('cost_of_sales');
        $rows[] = $line('total', 'gross_profit', 'Gross profit', $now['gross'], $prior['gross'] ?? null);
        $section('operating_expenses');
        $rows[] = $line('total', 'operating_profit', 'Operating profit', $now['operating'], $prior['operating'] ?? null);
        $section('other_income');
        $section('finance');
        $rows[] = $line('total', 'profit_before_tax', 'Profit before tax', $now['net'], $prior['net'] ?? null);
        $rows[] = $line('total', 'net_profit', 'Net profit', $now['net'], $prior['net'] ?? null);
        $columns = [['key' => 'label', 'label' => 'Particulars', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount (৳)', 'type' => 'amount']];
        if ($compare) {
            $columns[] = ['key' => 'prior', 'label' => 'Previous year (৳)', 'type' => 'amount'];
        }

        return [
            'title' => 'Profit and loss',
            'period' => $period,
            'basis' => 'Posted vouchers only',
            'columns' => $columns,
            'rows' => $rows,
            'totals' => ['revenue' => (string) $now['totals']['revenue'], 'gross_profit' => (string) $now['gross'], 'net_profit' => (string) $now['net'],
                'income' => (string) $now['income'], 'expenses' => (string) $now['expenses'], ...($compare ? ['prior_net_profit' => (string) $prior['net']] : [])],
            'checks' => [['key' => 'net_profit', 'ok' => $now['income']->minus($now['expenses'])->isEqualTo($now['net']),
                'message' => 'Net profit = income ৳ '.$now['income'].' − expenses ৳ '.$now['expenses']]],
            'meta' => ['compare' => $compare ? 'prior' : 'none', 'detail' => $detail ? 'ledgers' : 'totals', 'branch_id' => $branchId,
                'gross_margin_pct' => $now['totals']['revenue']->isPositive() ? (string) $now['gross']->multipliedBy(100)->dividedBy($now['totals']['revenue'], 1) : null],
        ];
    }

    /** Section of a P&L ledger from its group path (06 §17). */
    public static function sectionOf(LedgerBalances $b, int $ledgerId): ?string
    {
        $nature = $b->nature($ledgerId);
        $path = $b->path($b->ledgers[$ledgerId]->account_group_id);

        return match (true) {
            $nature === 'income' => in_array('41', $path, true) ? 'revenue' : 'other_income',
            $nature === 'expense' => in_array('5', $path, true) ? 'cost_of_sales' : (in_array('62', $path, true) ? 'finance' : 'operating_expenses'),
            default => null,
        };
    }

    /** @param array<int, Money> $net */
    private function sections(LedgerBalances $b, array $net): array
    {
        $ledgers = array_fill_keys(array_keys(self::SECTIONS), []);
        $info = [];
        [$income, $expenses] = [Money::zero(), Money::zero()];
        foreach ($net as $id => $n) {
            $key = self::sectionOf($b, $id);
            if ($key === null || $n->isZero()) {
                continue;
            }
            $isIncome = in_array($key, ['revenue', 'other_income'], true);
            $ledgers[$key][$id] = $isIncome ? $n->negated() : $n; // income shows as a positive credit, expense as a positive debit
            $info[$id] = $b->ledgers[$id];
            $isIncome ? $income = $income->minus($n) : $expenses = $expenses->plus($n);
        }
        $totals = array_map(fn ($l) => Money::sum($l), $ledgers);
        $gross = $totals['revenue']->minus($totals['cost_of_sales']);
        $operating = $gross->minus($totals['operating_expenses']);

        return ['ledgers' => $ledgers, 'info' => $info, 'totals' => $totals, 'gross' => $gross, 'operating' => $operating,
            'net' => $operating->plus($totals['other_income'])->minus($totals['finance']), 'income' => $income, 'expenses' => $expenses];
    }
}
