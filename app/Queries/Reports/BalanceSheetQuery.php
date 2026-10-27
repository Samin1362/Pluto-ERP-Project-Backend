<?php

namespace App\Queries\Reports;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Balance sheet (H17; 06 §17; backlog P9.1-BE-02; FR-076): asset, liability and equity ledgers at `as_of`, by account
 * group, plus "Profit for the period" (the financial year to date P&L) until the year-end close moves it to retained
 * earnings; profit of earlier years that were never closed shows on its own line. Check: assets = equity +
 * liabilities. `compare=prior` adds the end of the previous financial year; `detail=totals` drops the ledger rows.
 *
 * Row: kind (heading|group|ledger|profit|subtotal|total), key, label, code, group_id, ledger_id, depth, amount, prior.
 */
final class BalanceSheetQuery
{
    private const SECTIONS = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity'];

    public function report(int $companyId, string $asOf, bool $compare = false, bool $detail = true): array
    {
        $b = new LedgerBalances($companyId);
        $now = $this->at($b, $companyId, $asOf);
        $period = ['as_of' => $asOf];
        $prior = null;
        if ($compare) {
            $priorDate = $this->previousYearEnd($companyId, $asOf);
            $prior = $this->at($b, $companyId, $priorDate);
            $period['prior_as_of'] = $priorDate;
        }
        $rows = [];
        $amounts = fn (Money $a, ?Money $p) => ['amount' => (string) $a, ...($compare ? ['prior' => (string) ($p ?? Money::zero())] : [])];
        foreach (self::SECTIONS as $nature => $title) {
            $rows[] = ['kind' => 'heading', 'key' => $nature, 'label' => $title, 'depth' => 0];
            $walk = function (object $g, int $depth) use (&$walk, &$rows, $b, $now, $prior, $amounts, $detail): void {
                $a = $now['groups'][$g->id] ?? Money::zero();
                $p = $prior['groups'][$g->id] ?? null;
                if ($a->isZero() && ($p === null || $p->isZero()) && ! $this->anyBalance($b, $g->id, $now, $prior)) {
                    return;
                }
                $rows[] = ['kind' => 'group', 'key' => "group_{$g->id}", 'label' => $g->name, 'code' => $g->code, 'group_id' => $g->id, 'depth' => $depth, ...$amounts($a, $p)];
                foreach ($b->childGroups[$g->id] ?? [] as $c) {
                    $walk($b->groups[$c], $depth + 1);
                }
                if ($detail) {
                    foreach ($b->ledgersOf[$g->id] ?? [] as $l) {
                        $la = $now['ledgers'][$l->id] ?? Money::zero();
                        $lp = $prior['ledgers'][$l->id] ?? null;
                        if (! $la->isZero() || ($lp !== null && ! $lp->isZero())) {
                            $rows[] = ['kind' => 'ledger', 'key' => "ledger_{$l->id}", 'label' => $l->name, 'code' => $l->code, 'ledger_id' => $l->id, 'group_id' => $g->id,
                                'depth' => $depth + 1, ...$amounts($la, $lp)];
                        }
                    }
                }
            };
            foreach (array_filter($b->roots(), fn ($g) => $g->nature === $nature) as $g) {
                foreach ($b->childGroups[$g->id] ?? [] as $c) {
                    $walk($b->groups[$c], 1);
                }
                foreach ($detail ? ($b->ledgersOf[$g->id] ?? []) : [] as $l) {
                    $la = $now['ledgers'][$l->id] ?? Money::zero();
                    $lp = $prior['ledgers'][$l->id] ?? null;
                    if (! $la->isZero() || ($lp !== null && ! $lp->isZero())) {
                        $rows[] = ['kind' => 'ledger', 'key' => "ledger_{$l->id}", 'label' => $l->name, 'code' => $l->code, 'ledger_id' => $l->id, 'group_id' => $g->id, 'depth' => 1, ...$amounts($la, $lp)];
                    }
                }
                if (! $detail && ($b->ledgersOf[$g->id] ?? []) !== []) {
                    $direct = Money::sum(array_map(fn ($l) => $now['ledgers'][$l->id] ?? Money::zero(), $b->ledgersOf[$g->id]));
                    $directPrior = $prior ? Money::sum(array_map(fn ($l) => $prior['ledgers'][$l->id] ?? Money::zero(), $b->ledgersOf[$g->id])) : null;
                    if (! $direct->isZero() || ($directPrior && ! $directPrior->isZero())) {
                        $rows[] = ['kind' => 'group', 'key' => "direct_{$g->id}", 'label' => "Other {$g->name}", 'group_id' => $g->id, 'depth' => 1, ...$amounts($direct, $directPrior)];
                    }
                }
            }
            if ($nature === 'equity') {
                if (! $now['earlier']->isZero() || ($prior && ! $prior['earlier']->isZero())) {
                    $rows[] = ['kind' => 'profit', 'key' => 'earlier_profit', 'label' => 'Profit of earlier years not yet closed', 'depth' => 1, ...$amounts($now['earlier'], $prior['earlier'] ?? null)];
                }
                $rows[] = ['kind' => 'profit', 'key' => 'profit_for_period', 'label' => 'Profit for the period', 'depth' => 1, ...$amounts($now['profit'], $prior['profit'] ?? null)];
            }
            $rows[] = ['kind' => 'subtotal', 'key' => "{$nature}_total", 'label' => "Total {$title}", 'depth' => 0, ...$amounts($now['totals'][$nature], $prior['totals'][$nature] ?? null)];
        }
        $le = $now['totals']['liability']->plus($now['totals']['equity']);
        $rows[] = ['kind' => 'total', 'key' => 'liabilities_and_equity', 'label' => 'Total liabilities and equity', 'depth' => 0,
            ...$amounts($le, $prior ? $prior['totals']['liability']->plus($prior['totals']['equity']) : null)];
        $difference = $now['totals']['asset']->minus($le);
        $columns = [['key' => 'label', 'label' => 'Particulars', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount (৳)', 'type' => 'amount']];
        if ($compare) {
            $columns[] = ['key' => 'prior', 'label' => 'Previous year end (৳)', 'type' => 'amount'];
        }

        return [
            'title' => 'Balance sheet',
            'period' => $period,
            'basis' => 'Posted vouchers only',
            'columns' => $columns,
            'rows' => $rows,
            'totals' => ['assets' => (string) $now['totals']['asset'], 'liabilities' => (string) $now['totals']['liability'], 'equity' => (string) $now['totals']['equity'],
                'liabilities_and_equity' => (string) $le, 'profit_for_period' => (string) $now['profit'], 'difference' => (string) $difference->abs()],
            'checks' => [['key' => 'balanced', 'ok' => $difference->isZero(), 'message' => $difference->isZero() ? 'Assets equal equity plus liabilities'
                : 'Assets and equity plus liabilities differ by ৳ '.$difference->abs()]],
            'meta' => ['compare' => $compare ? 'prior' : 'none', 'detail' => $detail ? 'ledgers' : 'totals', 'fy_start' => $now['fy_start']],
        ];
    }

    /** Balances at a date: asset ledgers Dr − Cr, liability and equity ledgers Cr − Dr, the P&L split into this year and earlier. */
    private function at(LedgerBalances $b, int $companyId, string $asOf): array
    {
        $net = $b->net(null, $asOf);
        $fyStart = (string) (DB::table('fiscal_years')->where('company_id', $companyId)->where('start_date', '<=', $asOf)->where('end_date', '>=', $asOf)->value('start_date')
            ?? CarbonImmutable::parse($asOf)->startOfYear()->toDateString());
        $ledgers = [];
        $totals = array_fill_keys(array_keys(self::SECTIONS), Money::zero());
        $unclosed = Money::zero(); // all P&L ever posted and not closed, as a credit (profit) amount
        foreach ($net as $id => $n) {
            $nature = $b->nature($id);
            if (isset(self::SECTIONS[$nature])) {
                $ledgers[$id] = $nature === 'asset' ? $n : $n->negated();
                $totals[$nature] = $totals[$nature]->plus($ledgers[$id]);
            } else {
                $unclosed = $unclosed->minus($n);
            }
        }
        $profit = Money::zero();
        foreach ($b->net($fyStart, $asOf, null, ['year_end_close']) as $id => $n) {
            if (! isset(self::SECTIONS[$b->nature($id)])) {
                $profit = $profit->minus($n);
            }
        }
        $earlier = $unclosed->minus($profit);
        $totals['equity'] = $totals['equity']->plus($profit)->plus($earlier);
        $groups = [];
        foreach ($b->rollUp($net) as $gid => $sum) {
            $groups[$gid] = $b->groups[$gid]->nature === 'asset' ? $sum : $sum->negated();
        }

        return ['ledgers' => $ledgers, 'groups' => $groups, 'totals' => $totals, 'profit' => $profit, 'earlier' => $earlier, 'fy_start' => $fyStart];
    }

    private function previousYearEnd(int $companyId, string $asOf): string
    {
        $start = DB::table('fiscal_years')->where('company_id', $companyId)->where('start_date', '<=', $asOf)->where('end_date', '>=', $asOf)->value('start_date');

        return CarbonImmutable::parse($start ?? CarbonImmutable::parse($asOf)->startOfYear())->subDay()->toDateString();
    }

    private function anyBalance(LedgerBalances $b, int $groupId, array $now, ?array $prior): bool
    {
        foreach ($b->ledgersOf[$groupId] ?? [] as $l) {
            if (! ($now['ledgers'][$l->id] ?? Money::zero())->isZero() || ($prior && ! ($prior['ledgers'][$l->id] ?? Money::zero())->isZero())) {
                return true;
            }
        }
        foreach ($b->childGroups[$groupId] ?? [] as $c) {
            if ($this->anyBalance($b, $c, $now, $prior)) {
                return true;
            }
        }

        return false;
    }
}
