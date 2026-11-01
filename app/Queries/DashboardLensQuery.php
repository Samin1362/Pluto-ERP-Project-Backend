<?php

namespace App\Queries;

use App\Domain\Dashboard\DashboardCache;
use App\Domain\Shared\Money;
use App\Queries\Reports\AgingQuery;
use App\Queries\Reports\InventoryReportQuery;
use App\Queries\Reports\LedgerBalances;
use App\Queries\Reports\ProfitAndLossQuery;
use App\Support\Problem\ApiProblem;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard lenses A2–A8 (05 GET /dashboard/lenses/{lens}; FR-011; backlog P14.1-BE-02): KPI tiles, one series for a
 * chart or bar list, one ReportData table linking onward. Each lens reads the query behind its report so the figures
 * agree. Permissions (DEC-13): financial needs reports.financial; cash reports.financial + banking.view; receivables
 * and payables reports.financial + parties.view; sales sales.view; expenses banking.view; inventory inventory.view
 * (values only with inventory.view_costs). Receivables, payables, cash and inventory are as of today; the others
 * cover the period. Cached like the main dashboard.
 *
 * Row and series points carry links (`url`, SPA paths) and `pct` (0–100) for drawing.
 */
final class DashboardLensQuery
{
    public const LENSES = ['financial', 'sales', 'expenses', 'receivables', 'payables', 'cash', 'inventory'];

    public function __construct(
        private readonly DashboardQuery $dashboard,
        private readonly ProfitAndLossQuery $pl,
        private readonly BankingQuery $banking,
        private readonly AgingQuery $aging,
        private readonly InventoryReportQuery $inventory,
        private readonly IncomeExpenseQuery $ie,
    ) {}

    /** @return list<string> the permissions a lens needs */
    public static function permissions(string $lens): array
    {
        return match ($lens) {
            'financial' => ['reports.financial'],
            'sales' => ['sales.view'],
            'expenses' => ['banking.view'],
            'receivables', 'payables' => ['parties.view', 'reports.financial'],
            'cash' => ['banking.view', 'reports.financial'],
            'inventory' => ['inventory.view'],
        };
    }

    /** @param  callable(string): bool  $can */
    public function lens(int $c, string $lens, string $period, callable $can): array
    {
        foreach (self::permissions($lens) as $p) {
            if (! $can($p)) {
                throw new ApiProblem('permission_denied', "This lens needs the {$p} permission.");
            }
        }
        $see = DashboardQuery::visibility($can);

        return DashboardCache::remember($c, "lens:{$lens}:{$period}:".implode('', array_map('intval', $see)), fn () => [
            'lens' => $lens, 'period' => DashboardQuery::period($period), ...$this->{$lens}($c, DashboardQuery::period($period), $see),
        ]);
    }

    /** A2: P&L tiles, net profit by month, the condensed P&L as a share of revenue, and the key ratios. */
    private function financial(int $c, array $p, array $see): array
    {
        $now = $this->pl->report($c, $p['date_from'], $p['date_to'], false, false);
        $before = $this->pl->report($c, $p['prior_from'], $p['prior_to'], false, false)['totals'];
        $t = $now['totals'];
        $months = $this->dashboard->months($c, $p['date_to']);
        $margin = fn (string $v) => Money::of($t['revenue'])->isPositive() ? (string) Money::of($v)->multipliedBy(100)->dividedBy($t['revenue'], 1) : null;
        $netPct = DashboardQuery::bars(array_column($months, 'net_profit'));

        $sub = collect($now['rows'])->whereIn('kind', ['subtotal', 'total'])->keyBy('key');
        $lines = [['revenue_total', 'Revenue'], ['cost_of_sales_total', 'Cost of sales'], ['gross_profit', 'Gross profit'], ['operating_expenses_total', 'Operating expenses'],
            ['operating_profit', 'Operating profit'], ['other_income_total', 'Other income'], ['finance_total', 'Depreciation and finance'], ['net_profit', 'Net profit']];
        $rows = array_map(fn ($l) => ['kind' => in_array($l[0], ['gross_profit', 'operating_profit', 'net_profit'], true) ? 'total' : 'line', 'key' => $l[0], 'label' => $l[1],
            'amount' => $sub[$l[0]]['amount'] ?? '0.00', 'share' => $margin($sub[$l[0]]['amount'] ?? '0.00')], $lines);

        $days = (int) CarbonImmutable::parse($p['date_from'])->diffInDays(CarbonImmutable::parse($p['date_to'])) + 1;
        $owed = $this->dashboard->owed($c);
        $purchases = (string) Money::of((string) DB::table('purchase_bills')->where('company_id', $c)->where('status', 'posted')
            ->whereBetween('bill_date', [$p['date_from'], $p['date_to']])->sum('total'));
        $daysOf = fn (string $balance, string $flow) => Money::of($flow)->isPositive() ? (string) Money::of($balance)->multipliedBy($days)->dividedBy($flow, 0) : null;
        $ratios = [
            ['key' => 'gross_margin', 'label' => 'Gross margin', 'value' => $margin($t['gross_profit']), 'unit' => '%', 'basis' => 'Gross profit ÷ revenue'],
            ['key' => 'net_margin', 'label' => 'Net margin', 'value' => $margin($t['net_profit']), 'unit' => '%', 'basis' => 'Net profit ÷ revenue'],
            ['key' => 'current_ratio', 'label' => 'Current ratio', 'value' => $this->currentRatio($c, $p['date_to']), 'unit' => '×', 'basis' => 'Current assets ÷ current liabilities, on '.$p['date_to']],
            ['key' => 'debtor_days', 'label' => 'Debtor days', 'value' => $daysOf($owed['receivable']['total'], $t['revenue']), 'unit' => 'days', 'basis' => 'Receivables today ÷ revenue × days in the period'],
            ['key' => 'creditor_days', 'label' => 'Creditor days', 'value' => $daysOf($owed['payable']['total'], $purchases), 'unit' => 'days', 'basis' => 'Payables today ÷ purchases × days in the period'],
        ];

        return [
            'kpis' => [
                DashboardQuery::kpi('revenue', 'Revenue', $t['revenue'], $before['revenue'], array_column(array_slice($months, -6), 'revenue'), null, '/reports/profit-and-loss'),
                DashboardQuery::kpi('gross_profit', 'Gross profit', $t['gross_profit'], $before['gross_profit'], [], ($m = $margin($t['gross_profit'])) ? "Gross margin {$m} %" : null, '/reports/profit-and-loss'),
                DashboardQuery::kpi('expenses', 'Expenses', $t['expenses'], $before['expenses'], array_column(array_slice($months, -6), 'expenses'), null, '/reports/profit-and-loss'),
                DashboardQuery::kpi('net_profit', 'Net profit', $t['net_profit'], $before['net_profit'], array_column(array_slice($months, -6), 'net_profit'),
                    ($m = $margin($t['net_profit'])) ? "Net margin {$m} %" : null, '/reports/profit-and-loss'),
            ],
            'series' => array_map(fn ($m, $i) => ['month' => $m['month'], 'label' => $m['label'], 'value' => $m['net_profit'], 'pct' => $netPct[$i],
                'negative' => Money::of($m['net_profit'])->isNegative()], $months, array_keys($months)),
            'table' => $this->report('Profit and loss at a glance', $p, 'Posted vouchers only; share = part of revenue',
                [['key' => 'label', 'label' => 'Particulars', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount (৳)', 'type' => 'amount'], ['key' => 'share', 'label' => 'Of revenue', 'type' => 'pct']],
                $rows, ['revenue' => $t['revenue'], 'net_profit' => $t['net_profit']], ['ratios' => $ratios, 'view_all' => '/reports/profit-and-loss']),
        ];
    }

    private function currentRatio(int $c, string $asOf): ?string
    {
        $groups = DB::table('account_groups')->where('company_id', $c)->whereIn('code', ['11', '21'])->pluck('id', 'code');
        if (! isset($groups['11'], $groups['21'])) {
            return null;
        }
        $b = new LedgerBalances($c);
        $roll = $b->rollUp($b->net(null, $asOf));
        $assets = $roll[$groups['11']] ?? Money::zero();
        $liabilities = ($roll[$groups['21']] ?? Money::zero())->negated();

        return $liabilities->isPositive() ? (string) $assets->toBigDecimal()->dividedBy($liabilities->toBigDecimal(), 2, RoundingMode::HalfUp) : null;
    }

    /** A3: sales tiles, sales by product category, top customers. */
    private function sales(int $c, array $p, array $see): array
    {
        $posted = fn (string $f, string $t) => DB::table('sales_invoices')->where('company_id', $c)->where('status', 'posted')->whereBetween('invoice_date', [$f, $t]);
        $value = (string) Money::of((string) $posted($p['date_from'], $p['date_to'])->sum('total'));
        $count = $posted($p['date_from'], $p['date_to'])->count();
        $prior = (string) Money::of((string) $posted($p['prior_from'], $p['prior_to'])->sum('total'));
        $quotes = DB::table('quotations')->where('company_id', $c)->whereIn('status', ['draft', 'sent', 'accepted']);
        $kpis = [
            DashboardQuery::kpi('sales', 'Sales', $value, $prior, [], "{$count} posted invoices", '/reports/sales'),
            DashboardQuery::kpi('average_invoice', 'Average invoice', $count ? (string) Money::of($value)->dividedBy($count, 2) : '0.00', null, [], null, '/sales/invoices'),
            DashboardQuery::kpi('open_quotations', 'Open quotations', (string) Money::of((string) (clone $quotes)->sum('total')), null, [], (clone $quotes)->count().' quotations', '/sales/quotations'),
        ];
        if ($see['financial']) {
            $kpis[] = DashboardQuery::kpi('to_collect', 'Still to collect', (string) Money::of((string) DB::table('sales_invoices')->where('company_id', $c)->where('status', 'posted')->sum('balance_due')),
                null, [], 'All posted invoices', '/customers/outstanding');
        }

        $byCategory = DB::table('sales_invoice_lines as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')->leftJoin('products as p', 'p.id', '=', 'l.product_id')
            ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')->where('i.company_id', $c)->where('i.status', 'posted')
            ->whereBetween('i.invoice_date', [$p['date_from'], $p['date_to']])->groupBy('pc.id', 'pc.name')
            ->selectRaw("pc.id, COALESCE(pc.name, 'No category') as name, SUM(l.net_amount) as value")->orderByDesc('value')->limit(8)->get();
        $pct = DashboardQuery::bars($byCategory->map(fn ($r) => (string) Money::of((string) $r->value))->all());

        $top = DB::table('sales_invoices as i')->join('parties as p', 'p.id', '=', 'i.party_id')->where('i.company_id', $c)->where('i.status', 'posted')
            ->whereBetween('i.invoice_date', [$p['date_from'], $p['date_to']])->groupBy('p.id', 'p.code', 'p.name')
            ->selectRaw('p.id, p.code, p.name, COUNT(*) n, SUM(i.total) value')->orderByDesc('value')->limit(10)->get();

        return [
            'kpis' => $kpis,
            'series' => $byCategory->values()->map(fn ($r, $i) => ['id' => $r->id, 'name' => $r->name, 'value' => (string) Money::of((string) $r->value), 'pct' => $pct[$i]])->all(),
            'table' => $this->report('Top customers', $p, 'Posted invoices in the period',
                [['key' => 'name', 'label' => 'Customer', 'type' => 'text'], ['key' => 'invoices', 'label' => 'Invoices', 'type' => 'qty'],
                    ['key' => 'value', 'label' => 'Sales (৳)', 'type' => 'amount'], ['key' => 'share', 'label' => 'Share', 'type' => 'pct']],
                $top->map(fn ($r) => ['kind' => 'party', 'party_id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'invoices' => (int) $r->n,
                    'value' => (string) Money::of((string) $r->value), 'share' => Money::of($value)->isPositive() ? (string) Money::of((string) $r->value)->multipliedBy(100)->dividedBy($value, 1) : null,
                    'url' => "/customers/{$r->id}"])->all(),
                ['value' => $value, 'count' => $count], ['view_all' => '/reports/sales']),
        ];
    }

    /** A4: expenses against budget, by category. */
    private function expenses(int $c, array $p, array $see): array
    {
        $o = $this->ie->overview($c, 'expense', $p['name']);
        $cats = array_values(array_filter($o['rows'], fn ($r) => Money::of($r['value'])->isPositive() || $r['budget'] !== null));
        $pct = DashboardQuery::bars(array_column($cats, 'value'));
        $prior = Money::sum(array_map(fn ($v) => Money::of((string) $v), $this->ieSpent($c, $p['prior_from'], $p['prior_to'])));
        $t = $o['totals'];

        return [
            'kpis' => array_values(array_filter([
                DashboardQuery::kpi('expenses', 'Expenses', $t['value'], (string) $prior, array_slice(array_column($o['meta']['months'], 'value'), -6), "{$t['count']} lines", '/expenses/list'),
                $t['budget'] !== null ? DashboardQuery::kpi('budget', 'Budget for the period', $t['budget'], null, [], $t['used_pct'] !== null ? "{$t['used_pct']} % used" : null, '/expenses/categories') : null,
                DashboardQuery::kpi('this_month', 'This month', $t['this_month'], null, [], null, '/expenses/list'),
            ])),
            'series' => array_map(fn ($r, $i) => ['id' => $r['id'], 'name' => $r['name'], 'value' => $r['value'], 'budget' => $r['budget'], 'used_pct' => $r['used_pct'],
                'status' => $r['status'], 'pct' => $pct[$i], 'url' => "/expenses/list?category={$r['id']}"], $cats, array_keys($cats)),
            'table' => $this->report('Expenses by category', $p, $o['basis'],
                [['key' => 'name', 'label' => 'Category', 'type' => 'text'], ['key' => 'value', 'label' => 'Spent (৳)', 'type' => 'amount'],
                    ['key' => 'used_pct', 'label' => 'Of budget', 'type' => 'pct'], ['key' => 'share', 'label' => 'Share', 'type' => 'pct']],
                array_map(fn ($r) => [...$r, 'url' => "/expenses/list?category={$r['id']}"], $o['rows']), ['value' => $t['value']],
                ['missing_receipts' => $t['missing_receipts'], 'view_all' => '/expenses/report']),
        ];
    }

    /** @return array<int, string> spent per expense category ledger in [from, to] (for the prior period's total) */
    private function ieSpent(int $c, string $from, string $to): array
    {
        return DB::table('journal_lines as l')->join('ie_categories as c', 'c.ledger_id', '=', 'l.ledger_id')->where('c.company_id', $c)->where('c.kind', 'expense')
            ->where('l.is_posted', 1)->whereBetween('l.entry_date', [$from, $to])->groupBy('l.ledger_id')->selectRaw('l.ledger_id, SUM(l.debit) - SUM(l.credit) v')->pluck('v', 'ledger_id')
            ->map(fn ($v) => (string) $v)->all();
    }

    private function receivables(int $c, array $p, array $see): array
    {
        return $this->owedLens($c, 'customer');
    }

    private function payables(int $c, array $p, array $see): array
    {
        return $this->owedLens($c, 'supplier');
    }

    /** A5 / A6: totals and aging buckets as of today, the parties with the largest balances (A6 adds the bills due soon). */
    private function owedLens(int $c, string $type): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $r = $this->aging->report($c, $type, $today);
        $owed = $this->dashboard->owed($c)[$type === 'customer' ? 'receivable' : 'payable'];
        $base = $type === 'customer' ? '/customers' : '/suppliers';
        $rows = collect($r['rows'])->sort(fn ($a, $b) => Money::of($b['total'])->compareTo($a['total']))->take(10)->map(fn ($x) => [...$x, 'url' => "{$base}/{$x['party_id']}"])->values()->all();
        $t = $r['totals'];

        return [
            'kpis' => [
                DashboardQuery::kpi('total', $type === 'customer' ? 'Customers owe' : 'We owe suppliers', $t['total'], null, [], "{$t['parties']} ".($type === 'customer' ? 'customers' : 'suppliers'), "{$base}/outstanding"),
                DashboardQuery::kpi('overdue', 'Overdue', $t['overdue'], null, [], ($s = DashboardQuery::share($t['overdue'], $t['total'])) ? "{$s} % of the total" : null, "{$base}/outstanding"),
                DashboardQuery::kpi('d90_plus', 'Over 90 days', $t['d90_plus'], null, [], null, $type === 'customer' ? '/reports/receivables-aging' : '/reports/payables-aging'),
                DashboardQuery::kpi('advance', 'Advances', $t['advance'], null, [], $type === 'customer' ? 'Received, not yet applied' : 'Paid, not yet applied', "{$base}/outstanding"),
            ],
            'series' => $owed['buckets'],
            'table' => [...$r, 'title' => 'Largest balances', 'rows' => $rows,
                'meta' => [...$r['meta'], 'view_all' => $type === 'customer' ? '/reports/receivables-aging' : '/reports/payables-aging',
                    ...($type === 'supplier' ? ['upcoming' => $this->dashboard->upcoming($c, 10)] : [])]],
        ];
    }

    /** A7: balances today, money in and out this month, 30 days of the combined balance, the accounts with reconciliation status. */
    private function cash(int $c, array $p, array $see): array
    {
        $o = $this->banking->overview($c);
        $t = $o['totals'];
        $points = DashboardQuery::spread(array_column($o['meta']['series'], 'balance'));

        return [
            'kpis' => [
                DashboardQuery::kpi('total', 'Cash and bank', $t['total'], null, [], "{$t['accounts']} accounts", '/banking/accounts'),
                DashboardQuery::kpi('cash', 'Cash in hand', $t['cash'], null, [], null, '/banking/cash-book'),
                DashboardQuery::kpi('in_this_month', 'In this month', $t['in_this_month'], null, [], 'Transfers left out', '/banking/transactions?direction=in'),
                DashboardQuery::kpi('out_this_month', 'Out this month', $t['out_this_month'], null, [], 'Transfers left out', '/banking/transactions?direction=out'),
            ],
            'series' => array_map(fn ($s, $i) => [...$s, 'pct' => $points[$i]], $o['meta']['series'], array_keys($o['meta']['series'])),
            'table' => [...$o, 'title' => 'Accounts', 'columns' => [...$o['columns'], ['key' => 'last_reconciled_on', 'label' => 'Reconciled', 'type' => 'date'],
                ['key' => 'unmatched_lines', 'label' => 'To match', 'type' => 'qty']],
                'rows' => array_map(fn ($a) => [...$a, 'url' => "/banking/accounts/{$a['id']}"], $o['rows']),
                'meta' => ['recent' => $o['meta']['recent'], 'unmatched_lines' => $t['unmatched_lines'], 'view_all' => '/banking']],
        ];
    }

    /** A8: products at or below their reorder level; stock by warehouse (value with inventory.view_costs, else units). */
    private function inventory(int $c, array $p, array $see): array
    {
        $low = $this->inventory->lowStock($c, [], $see['costs']);
        $wh = DB::table('stock_balances as b')->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->where('b.company_id', $c)->groupBy('w.id', 'w.name')
            ->selectRaw('w.id, w.name, SUM(b.qty_on_hand) qty, SUM(b.total_value) value')->orderBy('w.name')->get();
        $measure = $wh->map(fn ($w) => (string) Money::of((string) ($see['costs'] ? $w->value : $w->qty)))->all();
        $pct = DashboardQuery::bars($measure);
        $kpis = [];
        if ($see['costs']) {
            $kpis[] = DashboardQuery::kpi('stock_value', 'Stock value', (string) Money::sum($wh->map(fn ($w) => Money::of((string) $w->value))), null, [], $wh->count().' warehouses', '/inventory/valuation');
            $kpis[] = DashboardQuery::kpi('reorder_value', 'To reorder', $low['totals']['order_value'], null, [], "{$low['totals']['items']} items at or below the level", '/inventory/low-stock');
        }

        return [
            'kpis' => $kpis,
            'series' => $wh->values()->map(fn ($w, $i) => ['id' => $w->id, 'name' => $w->name, 'quantity' => (string) $w->qty,
                ...($see['costs'] ? ['value' => (string) Money::of((string) $w->value)] : []), 'pct' => $pct[$i], 'url' => "/inventory/warehouses/{$w->id}"])->all(),
            'table' => [...$low, 'rows' => array_map(fn ($r) => [...$r, 'url' => "/inventory/products/{$r['product_id']}"], $low['rows']),
                'meta' => [...$low['meta'], 'measure' => $see['costs'] ? 'value' : 'quantity', 'view_all' => '/inventory/low-stock']],
        ];
    }

    private function report(string $title, array $p, string $basis, array $columns, array $rows, array $totals, array $meta = []): array
    {
        return ['title' => $title, 'period' => ['from' => $p['date_from'], 'to' => $p['date_to']], 'basis' => $basis, 'columns' => $columns, 'rows' => $rows,
            'totals' => $totals, 'checks' => [], 'meta' => $meta];
    }
}
