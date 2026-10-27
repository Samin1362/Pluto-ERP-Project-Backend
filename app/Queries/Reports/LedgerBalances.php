<?php

namespace App\Queries\Reports;

use App\Domain\Shared\Money;
use Illuminate\Support\Facades\DB;

/**
 * The chart of accounts of one company with posted movements (BR-005), shared by the financial statements (06 §17;
 * backlog P9.1): account groups in display order, their ledgers, each ledger's net (Dr − Cr) over a date range and
 * the group roll-ups. A ledger belongs to every group on the path from its own group up to a primary group.
 */
final class LedgerBalances
{
    /** @var array<int, object> id => account_groups row */
    public array $groups = [];

    /** @var array<int, list<int>> */
    public array $childGroups = [];

    /** @var array<int, list<object>> group id => ledgers (id, code, name, account_group_id) */
    public array $ledgersOf = [];

    /** @var array<int, object> */
    public array $ledgers = [];

    public function __construct(public readonly int $companyId)
    {
        $this->groups = DB::table('account_groups')->where('company_id', $companyId)->orderBy('sort_order')->orderBy('code')->get()->keyBy('id')->all();
        foreach ($this->groups as $g) {
            if ($g->parent_id !== null) {
                $this->childGroups[$g->parent_id][] = $g->id;
            }
        }
        foreach (DB::table('ledgers')->where('company_id', $companyId)->orderBy('code')->get(['id', 'code', 'name', 'account_group_id']) as $l) {
            $this->ledgers[$l->id] = $l;
            $this->ledgersOf[$l->account_group_id][] = $l;
        }
    }

    /**
     * Net (Dr − Cr) per ledger of the posted lines dated in [from, to] (from = null: since the beginning).
     *
     * @param  list<string>  $exceptTypes  entry types left out (e.g. year_end_close for a period's profit)
     * @return array<int, Money>
     */
    public function net(?string $from, string $to, ?int $branchId = null, array $exceptTypes = []): array
    {
        $q = DB::table('journal_lines as l')->where('l.company_id', $this->companyId)->where('l.is_posted', 1)->where('l.entry_date', '<=', $to)
            ->when($from !== null, fn ($q) => $q->where('l.entry_date', '>=', $from));
        if ($branchId !== null || $exceptTypes !== []) {
            $q->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->when($branchId !== null, fn ($q) => $q->where('e.branch_id', $branchId))
                ->when($exceptTypes !== [], fn ($q) => $q->whereNotIn('e.entry_type', $exceptTypes));
        }
        $out = [];
        foreach ($q->groupBy('l.ledger_id')->selectRaw('l.ledger_id, SUM(l.debit) - SUM(l.credit) net')->pluck('net', 'ledger_id') as $id => $net) {
            $out[(int) $id] = Money::of((string) $net);
        }

        return $out;
    }

    /** @param array<int, Money> $net @return array<int, Money> group id => Σ net of every ledger under it */
    public function rollUp(array $net): array
    {
        $out = [];
        $walk = function (int $id) use (&$walk, &$out, $net): Money {
            $sum = Money::sum(array_map(fn ($l) => $net[$l->id] ?? Money::zero(), $this->ledgersOf[$id] ?? []));
            foreach ($this->childGroups[$id] ?? [] as $c) {
                $sum = $sum->plus($walk($c));
            }

            return $out[$id] = $sum;
        };
        foreach ($this->roots() as $g) {
            $walk($g->id);
        }

        return $out;
    }

    /** @return list<object> primary groups in display order */
    public function roots(): array
    {
        return array_values(array_filter($this->groups, fn ($g) => $g->parent_id === null));
    }

    /** @return list<string> codes of the group and its ancestors, nearest first */
    public function path(int $groupId): array
    {
        $codes = [];
        for ($g = $this->groups[$groupId] ?? null; $g; $g = $g->parent_id ? ($this->groups[$g->parent_id] ?? null) : null) {
            $codes[] = (string) $g->code;
        }

        return $codes;
    }

    /** The nearest non-null value of a group column on the path from the ledger's group up. */
    public function inherited(int $ledgerId, string $column): ?string
    {
        for ($g = $this->groups[$this->ledgers[$ledgerId]->account_group_id] ?? null; $g; $g = $g->parent_id ? ($this->groups[$g->parent_id] ?? null) : null) {
            if ($g->{$column} !== null) {
                return (string) $g->{$column};
            }
        }

        return null;
    }

    public function nature(int $ledgerId): string
    {
        return (string) $this->groups[$this->ledgers[$ledgerId]->account_group_id]->nature;
    }

    /** Dr/Cr cells of a net amount: debit balance in Dr, credit balance in Cr. */
    public static function drCr(Money $net): array
    {
        return ['debit' => $net->isNegative() ? null : (string) $net, 'credit' => $net->isNegative() ? (string) $net->abs() : null];
    }
}
