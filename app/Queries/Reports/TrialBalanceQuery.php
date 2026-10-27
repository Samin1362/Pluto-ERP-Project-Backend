<?php

namespace App\Queries\Reports;

use App\Domain\Shared\Money;
use Illuminate\Support\Facades\DB;

/**
 * Trial balance (H15; 06 §17; backlog P9.1-BE-01; BR-001, BR-005, TC-ACC-005): Σ posted lines per ledger up to `as_of`,
 * a debit balance in Dr and a credit balance in Cr. `level=group` shows the account groups only (rolled up),
 * `level=ledger` (default) the groups with their ledgers under them. Ledgers and groups with a zero balance are left
 * out. Totals add the ledgers, so they agree whatever the level. `meta.drafts` lists the vouchers not yet posted
 * (draft or submitted, dated up to `as_of`) that the report leaves out: the design's side panel.
 *
 * Row: kind (group|ledger), id, code, name, depth, debit, credit, group_id (ledgers), ledger_id (ledgers).
 */
final class TrialBalanceQuery
{
    public function report(int $companyId, string $asOf, string $level = 'ledger', ?int $branchId = null): array
    {
        $b = new LedgerBalances($companyId);
        $net = $b->net(null, $asOf, $branchId);
        $groupNet = $b->rollUp($net);
        $rows = [];
        $walk = function (object $g, int $depth) use (&$walk, &$rows, $b, $net, $groupNet, $level): void {
            if ($groupNet[$g->id]->isZero() && ! $this->anyMovement($b, $g->id, $net)) {
                return;
            }
            $rows[] = ['kind' => 'group', 'id' => $g->id, 'group_id' => $g->id, 'code' => $g->code, 'name' => $g->name, 'depth' => $depth, ...LedgerBalances::drCr($groupNet[$g->id])];
            foreach ($b->childGroups[$g->id] ?? [] as $c) {
                $walk($b->groups[$c], $depth + 1);
            }
            if ($level === 'ledger') {
                foreach ($b->ledgersOf[$g->id] ?? [] as $l) {
                    if (isset($net[$l->id]) && ! $net[$l->id]->isZero()) {
                        $rows[] = ['kind' => 'ledger', 'id' => $l->id, 'ledger_id' => $l->id, 'group_id' => $g->id, 'code' => $l->code, 'name' => $l->name,
                            'depth' => $depth + 1, ...LedgerBalances::drCr($net[$l->id])];
                    }
                }
            }
        };
        foreach ($b->roots() as $g) {
            $walk($g, 0);
        }
        [$dr, $cr] = [Money::zero(), Money::zero()];
        foreach ($net as $n) {
            $n->isNegative() ? $cr = $cr->plus($n->abs()) : $dr = $dr->plus($n);
        }
        $difference = $dr->minus($cr)->abs();
        $unbalanced = DB::table('journal_entries')->where('company_id', $companyId)->whereIn('status', ['posted', 'reversed'])->where('entry_date', '<=', $asOf)
            ->whereColumn('total_debit', '!=', 'total_credit')->count();
        $drafts = DB::table('journal_entries')->where('company_id', $companyId)->whereIn('status', ['draft', 'submitted'])->where('entry_date', '<=', $asOf)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId));

        return [
            'title' => 'Trial balance',
            'period' => ['as_of' => $asOf],
            'basis' => 'Posted vouchers only',
            'columns' => [
                ['key' => 'code', 'label' => 'Code', 'type' => 'code'], ['key' => 'name', 'label' => $level === 'group' ? 'Group' : 'Ledger', 'type' => 'text'],
                ['key' => 'debit', 'label' => 'Debit (৳)', 'type' => 'amount'], ['key' => 'credit', 'label' => 'Credit (৳)', 'type' => 'amount'],
            ],
            'rows' => $rows,
            'totals' => ['debit' => (string) $dr, 'credit' => (string) $cr, 'difference' => (string) $difference],
            'checks' => [
                ['key' => 'balanced', 'ok' => $difference->isZero(), 'message' => $difference->isZero() ? 'Debits equal credits'
                    : 'Debits and credits differ by ৳ '.$difference],
                ['key' => 'entries_balanced', 'ok' => $unbalanced === 0, 'message' => $unbalanced === 0 ? 'Every posted voucher balances'
                    : "{$unbalanced} posted voucher(s) do not balance"],
            ],
            'meta' => [
                'level' => $level, 'branch_id' => $branchId,
                'drafts' => ['count' => (clone $drafts)->count(), 'total' => (string) Money::of((string) (clone $drafts)->sum('total_debit')),
                    'items' => (clone $drafts)->orderByDesc('entry_date')->orderByDesc('id')->limit(20)->get(['id', 'voucher_number', 'entry_type', 'entry_date', 'status', 'total_debit'])
                        ->map(fn ($e) => ['id' => $e->id, 'voucher_number' => $e->voucher_number, 'entry_type' => $e->entry_type, 'date' => (string) $e->entry_date,
                            'status' => $e->status, 'amount' => (string) Money::of((string) $e->total_debit)])->all()],
            ],
        ];
    }

    /** A group whose ledgers net to zero is still shown when one of them has a non-zero balance. */
    private function anyMovement(LedgerBalances $b, int $groupId, array $net): bool
    {
        foreach ($b->ledgersOf[$groupId] ?? [] as $l) {
            if (isset($net[$l->id]) && ! $net[$l->id]->isZero()) {
                return true;
            }
        }
        foreach ($b->childGroups[$groupId] ?? [] as $c) {
            if ($this->anyMovement($b, $c, $net)) {
                return true;
            }
        }

        return false;
    }
}
