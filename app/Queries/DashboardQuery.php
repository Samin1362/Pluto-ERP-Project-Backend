<?php

namespace App\Queries;

use App\Domain\Dashboard\DashboardCache;
use App\Domain\Shared\Money;
use App\Queries\Reports\AgingQuery;
use App\Queries\Reports\InventoryReportQuery;
use App\Queries\Reports\LedgerBalances;
use App\Queries\Reports\ProfitAndLossQuery;
use App\Queries\Reports\TradeOverviewQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * A1 main dashboard (05 GET /dashboard; FR-010; backlog P14.1-BE-01). Every figure comes from the query behind the
 * matching report — P&L, banking overview, aging, low stock — so the dashboard and the reports agree for the same
 * period. Profit, cash and balances need reports.financial (DEC-13 / C7: not for Sales staff or Viewer); the other
 * widgets follow their module's view permission and are empty without it. Each widget is built on its own: one that
 * fails comes back empty with an entry in widget_errors (ERR-8). Cached per company, period and visibility for 60 s
 * and dropped by any posting (DashboardCache).
 *
 * Chart points carry `*_pct` (0–100 of the series' largest value) so the SPA draws without doing money maths.
 */
final class DashboardQuery
{
    public const PERIODS = ['this_month', 'this_quarter', 'this_year'];

    private array $errors = [];

    public function __construct(
        private readonly ProfitAndLossQuery $pl,
        private readonly BankingQuery $banking,
        private readonly AgingQuery $aging,
        private readonly InventoryReportQuery $inventory,
    ) {}

    /** @param  callable(string): bool  $can */
    public function summary(int $c, string $period, callable $can): array
    {
        $see = self::visibility($can);
        $data = DashboardCache::remember($c, 'main:'.$period.':'.implode('', array_map('intval', $see)), fn () => $this->build($c, $period, $see));
        // Banners name what waits for this user (approvals), so they are never shared through the cache.
        $this->errors = $data['widget_errors'];
        $data['banners'] = $this->widget('banners', fn () => $this->banners($c, $see, $can), []);
        $data['widget_errors'] = $this->errors;

        return $data;
    }

    /** @return array<string, bool> what this member may see on dashboards */
    public static function visibility(callable $can): array
    {
        return ['financial' => $can('reports.financial'), 'banking' => $can('banking.view'), 'sales' => $can('sales.view'), 'purchases' => $can('purchases.view'),
            'accounting' => $can('accounting.view'), 'inventory' => $can('inventory.view'), 'costs' => $can('inventory.view_costs'), 'reconcile' => $can('banking.reconcile')];
    }

    /** @return array{name: string, date_from: string, date_to: string, label: string, prior_from: string, prior_to: string} */
    public static function period(string $period): array
    {
        [$from, $to] = TradeOverviewQuery::range($period);
        $f = CarbonImmutable::parse($from);
        $t = CarbonImmutable::parse($to);
        // The same stretch of the previous month, quarter or year, for "vs last …".
        [$pf, $pt] = match ($period) {
            'this_quarter' => [$f->subMonthsNoOverflow(3), $t->subMonthsNoOverflow(3)],
            'this_year' => [$f->subYear(), $t->subYear()],
            default => [$f->subMonthNoOverflow(), $t->subMonthNoOverflow()],
        };
        $label = match ($period) {
            'this_quarter' => 'This quarter ('.$f->format('M').'–'.$t->format('M Y').')',
            'this_year' => 'Financial year to date (from '.$f->format('j M Y').')',
            default => $t->format('F Y'),
        };

        return ['name' => $period, 'date_from' => $from, 'date_to' => $to, 'label' => $label, 'prior_from' => $pf->toDateString(), 'prior_to' => $pt->toDateString()];
    }

    private function build(int $c, string $period, array $see): array
    {
        $this->errors = [];
        $p = self::period($period);
        $months = $see['financial'] ? $this->widget('revenue_vs_expenses', fn () => $this->months($c, $p['date_to']), []) : [];

        return [
            'period' => $p,
            'kpis' => $this->widget('kpis', fn () => $this->kpis($c, $p, $see, $months), []),
            'revenue_vs_expenses' => $months,
            'cash_position' => $see['financial'] && $see['banking'] ? $this->widget('cash_position', fn () => $this->cash($c), []) : [],
            'money_owed' => $see['financial'] ? $this->widget('money_owed', fn () => $this->owed($c), (object) []) : (object) [],
            'upcoming_payments' => $see['financial'] && $see['purchases'] ? $this->widget('upcoming_payments', fn () => $this->upcoming($c), []) : [],
            'recent_transactions' => $see['accounting'] ? $this->widget('recent_transactions', fn () => $this->recent($c, $see['financial']), []) : [],
            'inventory_alerts' => $see['inventory'] ? $this->widget('inventory_alerts', fn () => $this->alerts($c), []) : [],
            'today' => $see['accounting'] ? $this->widget('today', fn () => $this->today($c), (object) []) : (object) [],
            'banners' => [],
            'widget_errors' => $this->errors,
        ];
    }

    /** Runs one widget; on failure logs it and records { widget, ref } instead of failing the page (ERR-8). */
    private function widget(string $name, callable $build, mixed $empty): mixed
    {
        try {
            return $build();
        } catch (Throwable $e) {
            $ref = (string) (request()?->attributes->get('request_id') ?? Str::uuid());
            report($e);
            $this->errors[] = ['widget' => $name, 'ref' => $ref, 'message' => 'This part of the dashboard could not be loaded'];

            return $empty;
        }
    }

    private function kpis(int $c, array $p, array $see, array $months): array
    {
        $out = [];
        if ($see['financial']) {
            $now = $this->pl->report($c, $p['date_from'], $p['date_to'], false, false)['totals'];
            $before = $this->pl->report($c, $p['prior_from'], $p['prior_to'], false, false)['totals'];
            $last6 = array_slice($months, -6);
            $out[] = self::kpi('revenue', 'Revenue', $now['revenue'], $before['revenue'], array_column($last6, 'revenue'), 'Sales less returns and discounts', '/reports/profit-and-loss');
            $out[] = self::kpi('expenses', 'Expenses', $now['expenses'], $before['expenses'], array_column($last6, 'expenses'), 'Cost of sales and running costs', '/reports/profit-and-loss');
            $out[] = self::kpi('net_profit', 'Net profit', $now['net_profit'], $before['net_profit'], array_column($last6, 'net_profit'),
                $now['revenue'] !== '0.00' && Money::of($now['revenue'])->isPositive()
                    ? 'Net margin '.Money::of($now['net_profit'])->multipliedBy(100)->dividedBy($now['revenue'], 1).' %' : null, '/reports/profit-and-loss');
            if ($see['banking']) {
                $o = $this->banking->overview($c);
                $series = array_column($o['meta']['series'], 'balance');
                $trend = array_values(array_filter($series, fn ($i) => $i % 5 === 4 || $i === count($series) - 1, ARRAY_FILTER_USE_KEY));
                $out[] = self::kpi('cash', 'Cash and bank', $o['totals']['total'], null, $trend, $o['totals']['accounts'].' accounts · today', '/banking');
            }

            return $out;
        }
        foreach ([['sales', 'sales_invoices', 'invoice_date', 'Sales', 'Posted invoices', '/sales'], ['purchases', 'purchase_bills', 'bill_date', 'Purchases', 'Posted bills', '/purchases']] as [$k, $table, $col, $label, $foot, $link]) {
            if (! $see[$k]) {
                continue;
            }
            $sum = fn (string $f, string $t) => (string) Money::of((string) DB::table($table)->where('company_id', $c)->where('status', 'posted')->whereBetween($col, [$f, $t])->sum('total'));
            $count = DB::table($table)->where('company_id', $c)->where('status', 'posted')->whereBetween($col, [$p['date_from'], $p['date_to']])->count();
            $end = CarbonImmutable::parse($p['date_to'])->startOfMonth();
            $trend = [];
            for ($m = $end->subMonths(5); $m <= $end; $m = $m->addMonth()) {
                $trend[] = $sum($m->toDateString(), $m->endOfMonth()->toDateString());
            }
            $out[] = self::kpi($k, $label, $sum($p['date_from'], $p['date_to']), $sum($p['prior_from'], $p['prior_to']), $trend, "{$count} ".mb_strtolower($foot), $link);
        }

        return $out;
    }

    /** @param  list<string>  $trend */
    public static function kpi(string $key, string $label, string $value, ?string $prior, array $trend, ?string $foot, ?string $link = null): array
    {
        $delta = null;
        if ($prior !== null && ! Money::of($prior)->isZero()) {
            $delta = (string) Money::of($value)->minus($prior)->multipliedBy(100)->dividedBy(Money::of($prior)->abs(), 1);
        }

        return ['key' => $key, 'label' => $label, 'value' => (string) Money::of($value), 'delta_pct' => $delta, 'trend' => array_map(fn ($v) => (string) Money::of($v), $trend),
            'trend_pct' => self::spread($trend), 'foot' => $foot, 'link' => $link];
    }

    /** `part` as a percentage of `whole`, one decimal; null when the whole is zero. */
    public static function share(string $part, string $whole): ?string
    {
        return Money::of($whole)->isZero() ? null : (string) Money::of($part)->multipliedBy(100)->dividedBy($whole, 1);
    }

    /**
     * Sparkline positions: each value's place between the series' lowest (0) and highest (100), one decimal.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function spread(array $values): array
    {
        if ($values === []) {
            return [];
        }
        $m = array_map(fn ($v) => Money::of($v), $values);
        $min = array_reduce($m, fn ($a, $v) => $a === null || $v->isLessThan($a) ? $v : $a);
        $max = array_reduce($m, fn ($a, $v) => $a === null || $v->isGreaterThan($a) ? $v : $a);
        $span = $max->minus($min);

        return array_map(fn ($v) => $span->isZero() ? '50.0' : (string) $v->minus($min)->multipliedBy(100)->dividedBy($span, 1), $m);
    }

    /**
     * Bar heights: each value's share of the largest absolute value in the series (0–100, one decimal).
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function bars(array $values): array
    {
        $max = array_reduce($values, fn ($a, $v) => Money::of($v)->abs()->isGreaterThan($a) ? Money::of($v)->abs() : $a, Money::zero());

        return array_map(fn ($v) => $max->isZero() ? '0.0' : (string) Money::of($v)->abs()->multipliedBy(100)->dividedBy($max, 1), $values);
    }

    /**
     * The 12 months ending with the period's month: revenue, expenses and net profit as the P&L counts them (posted
     * lines, year-end close left out; revenue = section 41, expenses = every expense ledger).
     */
    public function months(int $c, string $to): array
    {
        $end = CarbonImmutable::parse($to)->startOfMonth();
        $start = $end->subMonths(11);
        $b = new LedgerBalances($c);
        $net = DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')->where('l.company_id', $c)->where('l.is_posted', 1)
            ->where('e.entry_type', '!=', 'year_end_close')->whereBetween('l.entry_date', [$start->toDateString(), $end->endOfMonth()->toDateString()])
            ->groupBy('l.ledger_id')->groupByRaw("DATE_FORMAT(l.entry_date, '%Y-%m')")
            ->selectRaw("l.ledger_id, DATE_FORMAT(l.entry_date, '%Y-%m') m, SUM(l.debit) - SUM(l.credit) net")->get();
        $sum = [];
        foreach ($net as $r) {
            $section = isset($b->ledgers[(int) $r->ledger_id]) ? ProfitAndLossQuery::sectionOf($b, (int) $r->ledger_id) : null;
            if ($section === null) {
                continue;
            }
            $sum[$r->m] ??= ['revenue' => Money::zero(), 'income' => Money::zero(), 'expenses' => Money::zero()];
            $n = Money::of((string) $r->net);
            if ($section === 'revenue') {
                $sum[$r->m]['revenue'] = $sum[$r->m]['revenue']->minus($n);
            }
            in_array($section, ['revenue', 'other_income'], true) ? $sum[$r->m]['income'] = $sum[$r->m]['income']->minus($n) : $sum[$r->m]['expenses'] = $sum[$r->m]['expenses']->plus($n);
        }
        $out = [];
        for ($m = $start; $m <= $end; $m = $m->addMonth()) {
            $s = $sum[$m->format('Y-m')] ?? ['revenue' => Money::zero(), 'income' => Money::zero(), 'expenses' => Money::zero()];
            $out[] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'revenue' => (string) $s['revenue'], 'expenses' => (string) $s['expenses'],
                'net_profit' => (string) $s['income']->minus($s['expenses'])];
        }
        $pct = self::bars([...array_column($out, 'revenue'), ...array_column($out, 'expenses')]);
        foreach ($out as $i => &$row) {
            $row['revenue_pct'] = $pct[$i];
            $row['expenses_pct'] = $pct[$i + 12];
        }

        return $out;
    }

    private function cash(int $c): array
    {
        return array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'account_type' => $a['account_type'], 'bank_name' => $a['bank_name'],
            'balance' => $a['balance'], 'share' => $a['share'], 'last_reconciled_on' => $a['last_reconciled_on']], $this->banking->overview($c)['rows']);
    }

    /** Receivables and payables by age, as the aging reports count them today. */
    public function owed(int $c): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $out = [];
        foreach (['receivable' => 'customer', 'payable' => 'supplier'] as $key => $type) {
            $t = $this->aging->report($c, $type, $today)['totals'];
            $buckets = [['key' => 'not_due', 'label' => 'Not due'], ['key' => 'd1_30', 'label' => '1–30 days'], ['key' => 'd31_60', 'label' => '31–60 days'],
                ['key' => 'd61_90', 'label' => '61–90 days'], ['key' => 'd90_plus', 'label' => '90+ days']];
            $pct = self::bars(array_map(fn ($b) => $t[$b['key']], $buckets));
            $out[$key] = ['total' => $t['total'], 'overdue' => $t['overdue'], 'advance' => $t['advance'], 'parties' => $t['parties'], 'as_of' => $today,
                'buckets' => array_map(fn ($b, $i) => [...$b, 'amount' => $t[$b['key']], 'pct' => $pct[$i]], $buckets, array_keys($buckets))];
        }

        return $out;
    }

    /** Bills to pay: overdue or due in the next 14 days, earliest first. */
    public function upcoming(int $c, int $limit = 6): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka');

        return DB::table('purchase_bills as b')->join('parties as p', 'p.id', '=', 'b.party_id')->where('b.company_id', $c)->where('b.status', 'posted')
            ->where('b.balance_due', '>', 0)->whereNotNull('b.due_date')->where('b.due_date', '<=', $today->addDays(14)->toDateString())
            ->orderBy('b.due_date')->orderBy('b.id')->limit($limit)->get(['b.id', 'b.number', 'p.name as party', 'b.party_id', 'b.due_date', 'b.balance_due'])
            ->map(fn ($r) => ['kind' => 'purchase_bill', 'id' => $r->id, 'number' => $r->number, 'party' => $r->party, 'party_id' => $r->party_id, 'due_date' => (string) $r->due_date,
                'amount' => (string) Money::of((string) $r->balance_due), 'days' => (int) $today->startOfDay()->diffInDays(CarbonImmutable::parse($r->due_date), false),
                'url' => "/purchases/bills/{$r->id}"])->all();
    }

    /** The latest posted vouchers with the party they touch (if any). */
    public function recent(int $c, bool $amounts = true, int $limit = 8): array
    {
        $rows = DB::table('journal_entries')->where('company_id', $c)->where('status', 'posted')->orderByDesc('posted_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'entry_date', 'voucher_number', 'entry_type', 'narration', 'total_debit']);
        $parties = DB::table('journal_lines as l')->join('parties as p', 'p.ledger_id', '=', 'l.ledger_id')->whereIn('l.journal_entry_id', $rows->pluck('id'))
            ->orderBy('l.line_no')->get(['l.journal_entry_id', 'p.name'])->groupBy('journal_entry_id')->map(fn ($g) => $g->first()->name);

        return $rows->map(fn ($r) => ['id' => $r->id, 'date' => (string) $r->entry_date, 'voucher_number' => $r->voucher_number, 'type' => $r->entry_type,
            'party' => $parties[$r->id] ?? null, 'narration' => $r->narration, 'amount' => (string) Money::of((string) $r->total_debit),
            'url' => "/accounting/vouchers/{$r->id}"])->all();
    }

    /**
     * A1 Today feed (UI redesign A+, PEP-478): vouchers posted since midnight in Dhaka, newest first, with the time, who
     * posted them and the party they touch. posted_at is stored in UTC, so the day's bounds are converted. Same rows and
     * permission as recent_transactions (accounting.view).
     *
     * @return array{date: string, count: int, items: list<array<string, mixed>>}
     */
    public function today(int $c, int $limit = 8, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('Asia/Dhaka');
        $from = $now->setTimezone('Asia/Dhaka')->startOfDay();
        $range = [$from->utc()->toDateTimeString(), $from->addDay()->utc()->toDateTimeString()];
        $base = DB::table('journal_entries')->where('journal_entries.company_id', $c)->where('journal_entries.status', 'posted') // qualified: users also has status
            ->where('journal_entries.posted_at', '>=', $range[0])->where('journal_entries.posted_at', '<', $range[1]);
        $count = (clone $base)->count();
        $rows = (clone $base)->leftJoin('users as u', 'u.id', '=', 'journal_entries.posted_by')
            ->orderByDesc('journal_entries.posted_at')->orderByDesc('journal_entries.id')->limit($limit)
            ->get(['journal_entries.id', 'journal_entries.posted_at', 'journal_entries.voucher_number', 'journal_entries.entry_type', 'journal_entries.narration',
                'journal_entries.total_debit', 'u.name as by']);
        $parties = DB::table('journal_lines as l')->join('parties as p', 'p.ledger_id', '=', 'l.ledger_id')->whereIn('l.journal_entry_id', $rows->pluck('id'))
            ->orderBy('l.line_no')->get(['l.journal_entry_id', 'p.name'])->groupBy('journal_entry_id')->map(fn ($g) => $g->first()->name);

        return ['date' => $from->toDateString(), 'count' => $count, 'items' => $rows->map(function ($r) use ($parties) {
            $at = CarbonImmutable::parse($r->posted_at, 'UTC')->setTimezone('Asia/Dhaka');

            return ['id' => $r->id, 'posted_at' => $at->toIso8601String(), 'time' => $at->format('H:i'), 'type' => $r->entry_type, 'voucher_number' => $r->voucher_number,
                'party' => $parties[$r->id] ?? null, 'narration' => $r->narration, 'amount' => (string) Money::of((string) $r->total_debit),
                'by' => $r->by, 'url' => "/accounting/vouchers/{$r->id}"];
        })->all()];
    }

    private function alerts(int $c, int $limit = 6): array
    {
        $r = $this->inventory->lowStock($c, [], false);

        return array_map(fn ($i) => ['product_id' => $i['product_id'], 'code' => $i['code'], 'name' => $i['name'], 'warehouse' => $i['warehouse'], 'unit' => $i['unit'],
            'on_hand' => $i['on_hand'], 'reorder_level' => $i['reorder_level'], 'status' => $i['status'], 'total' => $r['totals']['items'],
            'url' => "/inventory/products/{$i['product_id']}"], array_slice($r['rows'], 0, $limit));
    }

    /** @param  callable(string): bool  $can */
    private function banners(int $c, array $see, callable $can): array
    {
        $out = [];
        if ($see['banking']) {
            $unmatched = DB::table('bank_statement_lines as s')->join('money_accounts as a', 'a.id', '=', 's.money_account_id')->where('s.company_id', $c)
                ->where('s.status', 'unmatched')->groupBy('a.id', 'a.name')->selectRaw('a.id, a.name, COUNT(*) n')->orderByDesc('n')->get();
            if ($unmatched->isNotEmpty()) {
                $n = (int) $unmatched->sum('n');
                $first = $unmatched->first();
                $out[] = ['kind' => 'reconcile', 'tone' => 'warning', 'title' => "{$n} bank statement ".Str::plural('line', $n).' not matched yet',
                    'body' => $unmatched->count() === 1 ? "{$first->name} has lines to reconcile." : "{$first->name} and {$unmatched->count()} accounts in all have lines to reconcile.",
                    'money_account_id' => $first->id, 'url' => $see['reconcile'] ? "/banking/accounts/{$first->id}" : null];
            }
        }
        $pending = DB::table('approval_requests')->where('company_id', $c)->where('status', 'pending')->where('requested_by', '!=', auth()->id())
            ->pluck('required_permission')->countBy();
        $mine = 0;
        foreach ($pending as $permission => $n) {
            $mine += $can((string) $permission) ? $n : 0;
        }
        if ($mine > 0) {
            $out[] = ['kind' => 'approvals', 'tone' => 'info', 'title' => "{$mine} ".Str::plural('voucher', $mine).' waiting for your approval', 'body' => null, 'url' => '/notifications?tab=approvals'];
        }

        return $out;
    }
}
