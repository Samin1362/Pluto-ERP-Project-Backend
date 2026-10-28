<?php

namespace App\Queries\Reports;

use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Sales overview (E1) and purchase overview (F1) as ReportData (backlog P10.5-BE-01). For the period (this_month by
 * default, last_month, this_quarter, this_year = the financial year to date):
 *  - totals: tiles — posted value and count, open quotations / purchase orders, and what is still to be received or
 *    paid (a balance, so null without reports.financial: DEC-13 / C7);
 *  - meta.months: the last 12 months' posted value (bars); meta.status: drafts, unpaid, part paid, paid and overdue
 *    (count and value); meta.recent: the 5 latest documents;
 *  - rows: top 5 products (sales) or suppliers (purchases) by value in the period.
 */
final class TradeOverviewQuery
{
    public function sales(int $c, string $period, bool $balances): array
    {
        [$from, $to] = self::range($period);
        $docs = DB::table('sales_invoices')->where('company_id', $c);
        $posted = (clone $docs)->where('status', 'posted')->whereBetween('invoice_date', [$from, $to]);
        $quotes = DB::table('quotations')->where('company_id', $c)->whereIn('status', ['draft', 'sent', 'accepted']);
        $top = DB::table('sales_invoice_lines as l')->join('sales_invoices as i', 'i.id', '=', 'l.sales_invoice_id')->where('i.company_id', $c)->where('i.status', 'posted')
            ->whereBetween('i.invoice_date', [$from, $to])->groupBy('l.product_id', 'l.product_code', 'l.product_name')
            ->selectRaw('l.product_id, l.product_code, l.product_name, SUM(l.quantity) qty, SUM(l.net_amount) value')->orderByDesc('value')->limit(5)->get();

        return [
            'title' => 'Sales overview',
            'period' => ['name' => $period, 'date_from' => $from, 'date_to' => $to],
            'basis' => 'Posted invoices; drafts and cancelled invoices are not counted',
            'columns' => [['key' => 'name', 'label' => 'Product', 'type' => 'text'], ['key' => 'quantity', 'label' => 'Quantity', 'type' => 'qty'],
                ['key' => 'value', 'label' => 'Sales (৳)', 'type' => 'amount']],
            'rows' => $top->map(fn ($r) => ['kind' => 'product', 'product_id' => $r->product_id, 'code' => $r->product_code, 'name' => $r->product_name,
                'quantity' => (string) $r->qty, 'value' => (string) Money::of((string) $r->value)])->all(),
            'totals' => [
                'value' => (string) Money::of((string) (clone $posted)->sum('total')), 'count' => (clone $posted)->count(),
                'open_quotes' => (clone $quotes)->count(), 'open_quotes_value' => (string) Money::of((string) (clone $quotes)->sum('total')),
                'outstanding' => $balances ? (string) Money::of((string) (clone $docs)->where('status', 'posted')->sum('balance_due')) : null,
            ],
            'checks' => [],
            'meta' => ['months' => $this->months($c, 'sales_invoices', 'invoice_date', $to), 'status' => $this->status($c, 'sales_invoices', 'invoice_date', $from, $to),
                'recent' => $this->recent($c, 'sales_invoices', 'invoice_date')],
        ];
    }

    public function purchases(int $c, string $period, bool $balances): array
    {
        [$from, $to] = self::range($period);
        $docs = DB::table('purchase_bills')->where('company_id', $c);
        $posted = (clone $docs)->where('status', 'posted')->whereBetween('bill_date', [$from, $to]);
        $orders = DB::table('purchase_orders')->where('company_id', $c)->whereIn('status', ['draft', 'sent', 'partly_received']);
        $top = DB::table('purchase_bills as b')->join('parties as p', 'p.id', '=', 'b.party_id')->where('b.company_id', $c)->where('b.status', 'posted')
            ->whereBetween('b.bill_date', [$from, $to])->groupBy('b.party_id', 'p.code', 'p.name')
            ->selectRaw('b.party_id, p.code, p.name, COUNT(*) n, SUM(b.total) value')->orderByDesc('value')->limit(5)->get();

        return [
            'title' => 'Purchase overview',
            'period' => ['name' => $period, 'date_from' => $from, 'date_to' => $to],
            'basis' => 'Posted bills in BDT; drafts and cancelled bills are not counted',
            'columns' => [['key' => 'name', 'label' => 'Supplier', 'type' => 'text'], ['key' => 'bills', 'label' => 'Bills', 'type' => 'qty'],
                ['key' => 'value', 'label' => 'Purchases (৳)', 'type' => 'amount']],
            'rows' => $top->map(fn ($r) => ['kind' => 'party', 'party_id' => $r->party_id, 'code' => $r->code, 'name' => $r->name, 'bills' => (int) $r->n,
                'value' => (string) Money::of((string) $r->value)])->all(),
            'totals' => [
                'value' => (string) Money::of((string) (clone $posted)->sum('total')), 'count' => (clone $posted)->count(),
                'open_orders' => (clone $orders)->count(), 'open_orders_value' => (string) Money::of((string) (clone $orders)->sum('total')),
                'outstanding' => $balances ? (string) Money::of((string) (clone $docs)->where('status', 'posted')->sum('balance_due')) : null,
            ],
            'checks' => [],
            'meta' => ['months' => $this->months($c, 'purchase_bills', 'bill_date', $to), 'status' => $this->status($c, 'purchase_bills', 'bill_date', $from, $to),
                'recent' => $this->recent($c, 'purchase_bills', 'bill_date')],
        ];
    }

    /** @return array{0: string, 1: string} the period's first and last day (Dhaka); this_year is the financial year to date. */
    public static function range(string $period): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();

        return match ($period) {
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'this_quarter' => [$today->startOfQuarter()->toDateString(), $today->toDateString()],
            'this_year' => [(DB::table('fiscal_years')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->value('start_date')
                ?? $today->startOfYear()->toDateString()), $today->toDateString()],
            default => [$today->startOfMonth()->toDateString(), $today->toDateString()],
        };
    }

    /** The 12 months ending with the period's month: posted value per month (empty months as 0). */
    private function months(int $c, string $table, string $dateCol, string $to): array
    {
        $end = CarbonImmutable::parse($to)->startOfMonth();
        $start = $end->subMonths(11);
        $sums = DB::table($table)->where('company_id', $c)->where('status', 'posted')->whereBetween($dateCol, [$start->toDateString(), $end->endOfMonth()->toDateString()])
            ->groupByRaw("DATE_FORMAT({$dateCol}, '%Y-%m')")->selectRaw("DATE_FORMAT({$dateCol}, '%Y-%m') m, SUM(total) v")->pluck('v', 'm');
        $out = [];
        for ($m = $start; $m->lessThanOrEqualTo($end); $m = $m->addMonth()) {
            $out[] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'value' => (string) Money::of((string) ($sums[$m->format('Y-m')] ?? '0'))];
        }

        return $out;
    }

    /** Drafts (any date), and the period's posted documents by payment status, with the overdue among them. */
    private function status(int $c, string $table, string $dateCol, string $from, string $to): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $base = DB::table($table)->where('company_id', $c);
        $row = fn ($q) => ['count' => (clone $q)->count(), 'value' => (string) Money::of((string) (clone $q)->sum('total'))];
        $posted = (clone $base)->where('status', 'posted')->whereBetween($dateCol, [$from, $to]);

        return [
            'draft' => $row((clone $base)->where('status', 'draft')),
            'unpaid' => $row((clone $posted)->where('payment_status', 'unpaid')),
            'part_paid' => $row((clone $posted)->where('payment_status', 'part_paid')),
            'paid' => $row((clone $posted)->where('payment_status', 'paid')),
            'overdue' => $row((clone $posted)->where('balance_due', '>', 0)->where('due_date', '<', $today)),
        ];
    }

    private function recent(int $c, string $table, string $dateCol): array
    {
        return DB::table("{$table} as d")->leftJoin('parties as p', 'p.id', '=', 'd.party_id')->where('d.company_id', $c)->whereIn('d.status', ['draft', 'posted'])
            ->orderByDesc("d.{$dateCol}")->orderByDesc('d.id')->limit(5)
            ->get(['d.id', 'd.number', "d.{$dateCol} as date", 'p.name as party', 'd.total', 'd.status', 'd.payment_status'])
            ->map(fn ($r) => ['id' => $r->id, 'number' => $r->number, 'date' => $r->date, 'party' => $r->party, 'total' => (string) Money::of((string) $r->total),
                'status' => $r->status, 'payment_status' => $r->payment_status])->all();
    }
}
