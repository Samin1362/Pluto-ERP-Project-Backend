<?php

namespace App\Queries;

use App\Domain\Shared\Money;
use Illuminate\Support\Facades\DB;

/**
 * The chart of accounts as a tree (H2/H3; 04 §11.3; backlog P7.1-BE-01): account groups from the five primary groups
 * down, each ledger's posted balance up to `as_of` (BR-005) rolled up through its groups, and the trial-balance check
 * (Σ debit balances = Σ credit balances). Balances are net Dr − Cr, shown as an amount and a side.
 */
final class AccountTreeQuery
{
    /** @var array<int, object> */
    private array $groups = [];

    /** @var array<int, list<int>> */
    private array $childGroups = [];

    /** @var array<int, list<object>> */
    private array $ledgersOf = [];

    /** @var array<int, Money> */
    private array $groupNet = [];

    public function tree(int $companyId, string $asOf, bool $withLedgers = true, bool $zero = true): array
    {
        $this->load($companyId, $asOf);
        $roots = array_values(array_filter($this->groups, fn ($g) => $g->parent_id === null));
        $nodes = array_values(array_filter(array_map(fn ($g) => $this->node($g, $withLedgers, $zero), $roots)));
        [$dr, $cr] = [Money::zero(), Money::zero()];
        foreach ($this->ledgersOf as $ledgers) {
            foreach ($ledgers as $l) {
                $l->net->isNegative() ? $cr = $cr->plus($l->net->abs()) : $dr = $dr->plus($l->net);
            }
        }

        return ['as_of' => $asOf, 'nodes' => $nodes, 'total_debit' => (string) $dr, 'total_credit' => (string) $cr, 'balanced' => $dr->isEqualTo($cr)];
    }

    /** One group (AccountGroup schema) with its rolled-up balance and its direct child groups and ledgers as nodes. */
    public function group(int $companyId, int $id, string $asOf): ?array
    {
        $this->load($companyId, $asOf);
        $g = $this->groups[$id] ?? null;
        if (! $g) {
            return null;
        }
        $net = $this->groupNet[$g->id];

        return ['id' => $g->id, 'code' => $g->code, 'name' => $g->name, 'parent_id' => $g->parent_id, 'nature' => $g->nature, 'normal_balance' => $g->normal_balance,
            'report_section' => $g->report_section, 'cash_flow_category' => $g->cash_flow_category, 'is_system' => (bool) $g->is_system,
            'used_in_cash_vouchers' => (bool) $g->used_in_cash_vouchers, 'sort_order' => (int) $g->sort_order,
            'balance' => (string) $net->abs(), 'balance_side' => $net->isNegative() ? 'cr' : 'dr',
            'children' => array_values(array_filter([...array_map(fn ($c) => $this->node($this->groups[$c], false, true), $this->childGroups[$g->id] ?? []),
                ...array_map(fn ($l) => $this->ledgerNode($l), $this->ledgersOf[$g->id] ?? [])])),
            'version' => (int) $g->lock_version];
    }

    /** @return array{0: array<int, object>, 1: array<int, Money>} the groups and their rolled-up net balances (Dr − Cr) */
    public function balances(int $companyId, string $asOf): array
    {
        $this->load($companyId, $asOf);

        return [$this->groups, $this->groupNet];
    }

    private function load(int $companyId, string $asOf): void
    {
        $this->groups = DB::table('account_groups')->where('company_id', $companyId)->orderBy('sort_order')->orderBy('code')->get()->keyBy('id')->all();
        $this->childGroups = [];
        foreach ($this->groups as $g) {
            if ($g->parent_id !== null) {
                $this->childGroups[$g->parent_id][] = $g->id;
            }
        }
        $balances = DB::table('journal_lines')->where('company_id', $companyId)->where('is_posted', 1)->where('entry_date', '<=', $asOf)
            ->groupBy('ledger_id')->selectRaw('ledger_id, SUM(debit) - SUM(credit) net')->pluck('net', 'ledger_id');
        $this->ledgersOf = [];
        foreach (DB::table('ledgers')->where('company_id', $companyId)->orderBy('code')->get(['id', 'account_group_id', 'code', 'name', 'ledger_kind', 'is_active']) as $l) {
            $l->net = Money::of((string) ($balances[$l->id] ?? '0'));
            $this->ledgersOf[$l->account_group_id][] = $l;
        }
        $this->groupNet = [];
        foreach ($this->groups as $g) {
            $this->rollUp($g->id);
        }
    }

    private function rollUp(int $groupId): Money
    {
        if (isset($this->groupNet[$groupId])) {
            return $this->groupNet[$groupId];
        }
        $net = Money::sum(array_map(fn ($l) => $l->net, $this->ledgersOf[$groupId] ?? []));
        foreach ($this->childGroups[$groupId] ?? [] as $c) {
            $net = $net->plus($this->rollUp($c));
        }

        return $this->groupNet[$groupId] = $net;
    }

    /** AccountNode of a group, or null when zero balances are hidden and nothing under it has a balance. */
    private function node(object $g, bool $withLedgers, bool $zero): ?array
    {
        $net = $this->groupNet[$g->id];
        $children = array_map(fn ($c) => $this->node($this->groups[$c], $withLedgers, $zero), $this->childGroups[$g->id] ?? []);
        if ($withLedgers) {
            $children = [...$children, ...array_map(fn ($l) => $zero || ! $l->net->isZero() ? $this->ledgerNode($l) : null, $this->ledgersOf[$g->id] ?? [])];
        }
        $children = array_values(array_filter($children));
        if (! $zero && $net->isZero() && $children === []) {
            return null;
        }

        return ['kind' => $g->parent_id === null ? 'primary' : 'group', 'id' => $g->id, 'code' => $g->code, 'name' => $g->name, 'nature' => $g->nature,
            'balance' => (string) $net->abs(), 'balance_side' => $net->isNegative() ? 'cr' : 'dr', 'note' => $g->is_system ? null : 'Added by you', 'children' => $children];
    }

    private function ledgerNode(object $l): array
    {
        $nature = $this->groups[$l->account_group_id]->nature ?? '';

        return ['kind' => 'ledger', 'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'nature' => $nature, 'balance' => (string) $l->net->abs(),
            'balance_side' => $l->net->isNegative() ? 'cr' : 'dr', 'note' => $l->is_active ? null : 'Inactive', 'children' => []];
    }
}
