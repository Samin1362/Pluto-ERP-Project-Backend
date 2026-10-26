<?php

namespace App\Queries;

use App\Domain\Shared\Money;
use App\Models\Ledger;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read model for GET /ledgers (backlog P1.1-BE-03): chart filters, picker purposes, and the posted balance and
 * opening balance as SQL subqueries (DECIMAL in SQL, strings in PHP; no N+1).
 */
final class LedgerQuery
{
    /**
     * Picker purposes (05 getLedgers): which ledgers each form may choose, and the create/edit permissions that
     * let a caller without accounting.view ask for them (R-26).
     *
     * @var array<string, array{natures: list<string>, kinds: list<string>, permissions: list<string>}>
     */
    public const PURPOSES = [
        'income_for_product' => ['natures' => ['income'], 'kinds' => ['general'], 'permissions' => ['inventory.create', 'inventory.edit']],
        'expense_for_product' => ['natures' => ['expense'], 'kinds' => ['general'], 'permissions' => ['inventory.create', 'inventory.edit']],
        'purchase_for_supplier' => ['natures' => ['expense'], 'kinds' => ['general'], 'permissions' => ['parties.create', 'parties.edit']],
        'tax' => ['natures' => ['asset', 'liability'], 'kinds' => ['tax'], 'permissions' => ['inventory.create', 'inventory.edit', 'settings.create', 'settings.edit']],
        'category' => ['natures' => ['income', 'expense'], 'kinds' => ['general'], 'permissions' => ['banking.create', 'banking.edit', 'settings.create', 'settings.edit']],
    ];

    /** Sub-ledgers that pickers and the default chart list leave out unless `kind` asks for them. */
    private const SUB_LEDGER_KINDS = ['party', 'bank', 'cash', 'inventory'];

    public function __construct(private readonly CurrentCompany $current) {}

    /** One ledger with its group nature, has_entries and (with accounting.view) balances, whatever its kind or status. */
    public function find(int $id, bool $withBalances = true): Ledger
    {
        return $this->base($withBalances)->where('ledgers.id', $id)->firstOrFail();
    }

    /** H5 aside: the current financial year's opening, debits, credits and closing on this ledger (posted lines). */
    public function fySummary(int $ledgerId): ?array
    {
        $today = now('Asia/Dhaka')->toDateString();
        $fy = DB::table('fiscal_years')->where('company_id', $this->current->id())->where('start_date', '<=', $today)->where('end_date', '>=', $today)->first();
        if (! $fy) {
            return null;
        }
        $lines = DB::table('journal_lines')->where('ledger_id', $ledgerId)->where('is_posted', 1);
        $opening = Money::of((string) (clone $lines)->where('entry_date', '<', $fy->start_date)->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b'));
        $year = (clone $lines)->whereBetween('entry_date', [$fy->start_date, $fy->end_date])
            ->selectRaw('CAST(COALESCE(SUM(debit), 0) AS DECIMAL(18,2)) dr, CAST(COALESCE(SUM(credit), 0) AS DECIMAL(18,2)) cr, COUNT(*) n')->first();
        $closing = $opening->plus((string) $year->dr)->minus((string) $year->cr);

        return ['fiscal_year' => $fy->name, 'from' => $fy->start_date, 'to' => $fy->end_date,
            'opening' => (string) $opening->abs(), 'opening_side' => $opening->isNegative() ? 'cr' : 'dr',
            'debit' => (string) Money::of((string) $year->dr), 'credit' => (string) Money::of((string) $year->cr), 'entries' => (int) $year->n,
            'closing' => (string) $closing->abs(), 'closing_side' => $closing->isNegative() ? 'cr' : 'dr'];
    }

    public function list(array $f, bool $withBalances): Builder
    {
        $q = $this->base($withBalances);

        if (isset($f['purpose']) && $f['purpose'] !== 'any') {
            $p = self::PURPOSES[$f['purpose']];
            $q->whereIn('account_groups.nature', $p['natures'])->whereIn('ledgers.ledger_kind', $p['kinds']);
        }
        if (isset($f['kind'])) {
            $q->where('ledgers.ledger_kind', $f['kind']);
        } else {
            $q->whereNotIn('ledgers.ledger_kind', self::SUB_LEDGER_KINDS);
        }
        if (isset($f['nature'])) {
            $q->where('account_groups.nature', $f['nature']);
        }
        if (isset($f['account_group_id'])) {
            $q->where('ledgers.account_group_id', $f['account_group_id']);
        }
        if (isset($f['cash_vouchers'])) {
            $q->where('ledgers.show_in_cash_vouchers', $f['cash_vouchers'] === 'true');
        }
        $active = $f['active'] ?? 'true';
        if ($active !== 'all') {
            $q->where('ledgers.is_active', $active === 'true');
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('ledgers.name', 'like', $term)->orWhere('ledgers.code', 'like', $term));
        }
        $sort = $f['sort'] ?? 'code';

        return $q->orderBy('ledgers.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('ledgers.id');
    }

    private function base(bool $withBalances): Builder
    {
        $cid = $this->current->id();
        $q = Ledger::query()->select('ledgers.*')
            ->join('account_groups', 'account_groups.id', '=', 'ledgers.account_group_id')
            ->addSelect(['account_groups.nature as group_nature', 'account_groups.normal_balance as group_normal_balance'])
            ->selectSub(DB::table('journal_lines')->selectRaw('COUNT(*) > 0')
                ->whereColumn('journal_lines.ledger_id', 'ledgers.id')->where('journal_lines.company_id', $cid), 'has_entries');
        if ($withBalances) {
            $q->selectSub(DB::table('journal_lines')->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2))')
                ->whereColumn('journal_lines.ledger_id', 'ledgers.id')->where('journal_lines.company_id', $cid)
                ->where('journal_lines.is_posted', true), 'balance_net');
            $q->selectSub(DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->selectRaw('CAST(COALESCE(SUM(journal_lines.debit) - SUM(journal_lines.credit), 0) AS DECIMAL(18,2))')
                ->whereColumn('journal_lines.ledger_id', 'ledgers.id')->where('journal_lines.company_id', $cid)
                ->where('journal_entries.entry_type', 'opening_balance')->where('journal_entries.status', 'posted'), 'opening_net');
        }

        return $q;
    }

    /** For every account group of the company: group names from the primary group down to that group. */
    public function groupPaths(): array
    {
        $groups = DB::table('account_groups')->where('company_id', $this->current->id())->get(['id', 'parent_id', 'name'])->keyBy('id');
        $paths = [];
        foreach ($groups as $id => $g) {
            $path = [];
            for ($n = $g, $guard = 0; $n && $guard < 20; $n = $n->parent_id ? ($groups[$n->parent_id] ?? null) : null, $guard++) {
                array_unshift($path, $n->name);
            }
            $paths[$id] = $path;
        }

        return $paths;
    }
}
