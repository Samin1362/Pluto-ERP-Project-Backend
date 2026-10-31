<?php

namespace App\Queries;

use App\Domain\Shared\Money;
use App\Queries\Reports\TradeOverviewQuery;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Income and expense reads (I1, I2, I4, I5, I7, I8, I9; backlog P13.1-BE-02/03/05; GAP-16). Everything counts posted
 * lines on the categories' ledgers, whatever voucher or document made them: a bill line on Rent is rent. An expense
 * line's amount is debit − credit, an income line's credit − debit, so a reversal nets out.
 *  - categories: spent in the period against the monthly budget × the months the period touches; status on_track,
 *    near_limit (from warn_at_percent), over_budget, not_used (nothing yet) or no_budget;
 *  - entries: the lines with their voucher, the money account and method when paid in cash or bank, and whether a
 *    receipt is attached (to the voucher, or to the bill that made it); meta.total_amount sums the filtered lines;
 *  - overview: tiles, by category, the last 12 months against the budget, the latest entries;
 *  - report: category × month (or quarter) with the total and each category's share.
 */
final class IncomeExpenseQuery
{
    public function categories(int $c, string $kind, string $period): array
    {
        [$from, $to] = TradeOverviewQuery::range($period);
        $months = self::monthsIn($from, $to);
        $spent = $this->byLedger($c, $kind, $from, $to);

        return DB::table('ie_categories as c')->join('ledgers as g', 'g.id', '=', 'c.ledger_id')->where('c.company_id', $c)->where('c.kind', $kind)
            ->orderBy('c.name')->get(['c.*', 'g.code as ledger_code'])
            ->map(fn ($r) => $this->category($r, Money::of((string) ($spent[$r->ledger_id] ?? '0')), $months))->all();
    }

    public function category(object $r, Money $spent, int $months): array
    {
        $budget = $r->monthly_budget !== null ? Money::of((string) $r->monthly_budget)->multipliedBy($months) : null;
        $pct = $budget && $budget->isPositive() ? (string) $spent->toBigDecimal()->multipliedBy(100)->dividedBy($budget->toBigDecimal(), 2, RoundingMode::HALF_UP) : null;
        $status = match (true) {
            $budget === null => $spent->isZero() ? 'not_used' : 'no_budget',
            $spent->isZero() => 'not_used',
            $pct !== null && BigDecimal::of($pct)->isGreaterThan(100) => 'over_budget',
            $pct !== null && BigDecimal::of($pct)->isGreaterThanOrEqualTo((string) $r->warn_at_percent) => 'near_limit',
            default => 'on_track',
        };

        return ['id' => $r->id, 'kind' => $r->kind, 'name' => $r->name, 'description' => $r->description, 'ledger_id' => $r->ledger_id, 'ledger_code' => $r->ledger_code,
            'monthly_budget' => $r->monthly_budget !== null ? (string) Money::of((string) $r->monthly_budget) : null, 'warn_at_percent' => number_format((float) $r->warn_at_percent, 4, '.', ''),
            'spent' => (string) $spent, 'used_pct' => $pct, 'status' => $status];
    }

    /** @return array{0: array, 1: int, 2: string} rows (IeEntry), total count and the filtered total amount */
    public function entries(int $c, array $f): array
    {
        $kind = $f['kind'] ?? 'expense';
        $q = $this->lines($c, $kind)
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('c.id', $v))
            ->when($f['date_from'] ?? null, fn ($q, $v) => $q->where('l.entry_date', '>=', $v))
            ->when($f['date_to'] ?? null, fn ($q, $v) => $q->where('l.entry_date', '<=', $v))
            ->when(in_array($f['missing_receipt'] ?? null, ['true', '1', true], true), fn ($q) => $q->whereRaw('NOT '.$this->hasReceiptSql()))
            ->when($f['q'] ?? null, function ($q, $v) {
                $like = '%'.addcslashes($v, '%_\\').'%';
                $q->where(fn ($w) => $w->where('e.voucher_number', 'like', $like)->orWhere('l.line_narration', 'like', $like)->orWhere('p.payee_text', 'like', $like)
                    ->orWhere('y.name', 'like', $like)->orWhere('e.narration', 'like', $like)->orWhere('c.name', 'like', $like));
            });
        $total = (clone $q)->count();
        $sum = Money::of((string) (DB::query()->fromSub((clone $q)->selectRaw($this->amountSql($kind).' as a'), 's')->sum('a') ?? '0'));
        $sort = $f['sort'] ?? '-date';
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $col = ltrim($sort, '-') === 'amount' ? DB::raw($this->amountSql($kind)) : 'l.entry_date';
        $per = (int) ($f['per_page'] ?? 25);
        $rows = $q->orderBy($col, $dir)->orderBy('l.id', $dir)->forPage((int) ($f['page'] ?? 1), $per)->get($this->columns($kind))->map(fn ($r) => self::entry($r))->all();

        return [$rows, $total, (string) $sum];
    }

    public function overview(int $c, string $kind, string $period): array
    {
        [$from, $to] = TradeOverviewQuery::range($period);
        $categories = collect($this->categories($c, $kind, $period));
        $total = Money::sum($categories->pluck('spent')->map(fn ($v) => Money::of($v)));
        $budgets = $categories->pluck('monthly_budget')->filter();
        $budget = $budgets->isEmpty() ? null : Money::sum($budgets->map(fn ($v) => Money::of($v))->all())->multipliedBy(self::monthsIn($from, $to));
        $monthStart = CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->toDateString();
        $thisMonth = Money::sum(collect($this->byLedger($c, $kind, $monthStart, CarbonImmutable::now('Asia/Dhaka')->toDateString()))->map(fn ($v) => Money::of((string) $v))->all());
        $period_q = $this->lines($c, $kind)->whereBetween('l.entry_date', [$from, $to]);
        $noun = $kind === 'expense' ? 'Expenses' : 'Income';

        return [
            'title' => $kind === 'expense' ? 'Expense overview' : 'Income overview',
            'period' => ['name' => $period, 'date_from' => $from, 'date_to' => $to],
            'basis' => 'Posted lines on the '.($kind === 'expense' ? 'expense' : 'income').' categories\' ledgers, from any voucher or document',
            'columns' => [['key' => 'name', 'label' => 'Category', 'type' => 'text'], ['key' => 'ledger_code', 'label' => 'Ledger', 'type' => 'code'],
                ['key' => 'value', 'label' => "{$noun} (৳)", 'type' => 'amount'], ['key' => 'share', 'label' => 'Share', 'type' => 'pct']],
            'rows' => $categories->sort(fn ($a, $b) => Money::of($b['spent'])->compareTo($a['spent']))->values()->map(fn ($r) => ['kind' => 'category', 'id' => $r['id'], 'name' => $r['name'],
                'ledger_id' => $r['ledger_id'], 'ledger_code' => $r['ledger_code'], 'value' => $r['spent'], 'share' => self::share(Money::of($r['spent']), $total),
                'budget' => $r['monthly_budget'], 'used_pct' => $r['used_pct'], 'status' => $r['status']])->all(),
            'totals' => ['value' => (string) $total, 'count' => (clone $period_q)->count(), 'this_month' => (string) $thisMonth,
                'budget' => $budget !== null ? (string) $budget : null,
                'used_pct' => $budget && $budget->isPositive() ? (string) $total->toBigDecimal()->multipliedBy(100)->dividedBy($budget->toBigDecimal(), 2, RoundingMode::HALF_UP) : null,
                'missing_receipts' => (clone $period_q)->whereRaw('NOT '.$this->hasReceiptSql())->count()],
            'checks' => [],
            'meta' => ['months' => $this->months($c, $kind, $to, $budgets->isEmpty() ? null : Money::sum($budgets->map(fn ($v) => Money::of($v))->all())),
                'recent' => $this->lines($c, $kind)->orderByDesc('l.entry_date')->orderByDesc('l.id')->limit(5)->get($this->columns($kind))->map(fn ($r) => self::entry($r))->all()],
        ];
    }

    /** I8 / I9: category × month (or quarter) for the range (the financial year to date by default). */
    public function report(int $c, string $kind, array $f): array
    {
        [$fyFrom, $today] = TradeOverviewQuery::range('this_year');
        $from = $f['date_from'] ?? $fyFrom;
        $to = $f['date_to'] ?? $today;
        $by = ($f['group_by'] ?? 'month') === 'quarter' ? 'quarter' : 'month';
        $buckets = self::buckets($from, $to, $by);
        $bucketSql = $by === 'quarter' ? "CONCAT(YEAR(l.entry_date), '-Q', QUARTER(l.entry_date))" : "DATE_FORMAT(l.entry_date, '%Y-%m')";
        $sums = $this->lines($c, $kind)->whereBetween('l.entry_date', [$from, $to])
            ->when($f['branch_id'] ?? null, fn ($q, $v) => $q->where('e.branch_id', $v))
            ->when($f['party_id'] ?? null, fn ($q, $v) => $q->where('p.party_id', $v))
            ->groupBy('c.id', DB::raw($bucketSql))->selectRaw("c.id cid, {$bucketSql} b, SUM(".$this->amountSql($kind).') v')->get()->groupBy('cid');
        $cats = DB::table('ie_categories as c')->join('ledgers as g', 'g.id', '=', 'c.ledger_id')->where('c.company_id', $c)->where('c.kind', $kind)->orderBy('c.name')
            ->get(['c.id', 'c.name', 'g.code', 'g.name as ledger_name']);
        $grand = Money::zero();
        $colTotals = array_fill_keys(array_column($buckets, 'key'), Money::zero());
        $rows = [];
        foreach ($cats as $cat) {
            $vals = collect($sums[$cat->id] ?? [])->mapWithKeys(fn ($s) => [self::bucketKey($s->b, $by) => Money::of((string) $s->v)]);
            $row = ['kind' => 'category', 'id' => $cat->id, 'name' => $cat->name, 'ledger' => "{$cat->code} {$cat->ledger_name}"];
            $sum = Money::zero();
            foreach ($buckets as $b) {
                $v = $vals[$b['key']] ?? Money::zero();
                $row[$b['key']] = (string) $v;
                $colTotals[$b['key']] = $colTotals[$b['key']]->plus($v);
                $sum = $sum->plus($v);
            }
            $row['total'] = (string) $sum;
            $grand = $grand->plus($sum);
            $rows[] = $row;
        }
        foreach ($rows as &$r) {
            $r['share'] = self::share(Money::of($r['total']), $grand);
        }
        unset($r);
        usort($rows, fn ($a, $b) => Money::of($b['total'])->compareTo($a['total']));
        $noun = $kind === 'expense' ? 'Expense' : 'Income';

        return [
            'title' => "{$noun} report",
            'period' => ['from' => $from, 'to' => $to, 'date_from' => $from, 'date_to' => $to],
            'basis' => 'Posted lines on the '.strtolower($noun).' categories\' ledgers, from any voucher or document',
            'columns' => [['key' => 'name', 'label' => 'Category', 'type' => 'text'], ['key' => 'ledger', 'label' => 'Ledger', 'type' => 'text'],
                ...array_map(fn ($b) => ['key' => $b['key'], 'label' => $b['label'].' (৳)', 'type' => 'amount'], $buckets),
                ['key' => 'total', 'label' => 'Total (৳)', 'type' => 'amount'], ['key' => 'share', 'label' => 'Share', 'type' => 'pct']],
            'rows' => $rows,
            'totals' => ['name' => 'Total', ...array_map(fn ($v) => (string) $v, $colTotals), 'total' => (string) $grand, 'share' => $grand->isPositive() ? '100.00' : null],
            'checks' => [],
            'meta' => ['group_by' => $by, 'buckets' => $buckets, 'categories' => count($rows)],
        ];
    }

    /** Posted lines on the kind's category ledgers, with the voucher, its payment detail, account and party. */
    private function lines(int $c, string $kind): Builder
    {
        return DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('ie_categories as c', fn ($j) => $j->on('c.ledger_id', '=', 'l.ledger_id')->where('c.kind', $kind)->where('c.company_id', $c))
            ->leftJoin('payment_details as p', 'p.journal_entry_id', '=', 'e.id')->leftJoin('money_accounts as m', 'm.id', '=', 'p.money_account_id')
            ->leftJoin('parties as y', 'y.id', '=', 'p.party_id')
            ->where('e.company_id', $c)->where('l.is_posted', true)->where('e.status', 'posted');
    }

    private function columns(string $kind): array
    {
        return ['l.id as line_id', 'e.id as voucher_id', 'e.voucher_number', 'l.entry_date', 'c.name as category', 'm.name as account', 'p.method',
            DB::raw('COALESCE(NULLIF(l.line_narration, \'\'), NULLIF(p.payee_text, \'\'), y.name, NULLIF(e.narration, \'\'), e.voucher_number) as description'),
            DB::raw($this->amountSql($kind).' as amount'), DB::raw($this->hasReceiptSql().' as has_receipt')];
    }

    private static function entry(object $r): array
    {
        return ['journal_line_id' => $r->line_id, 'voucher_id' => $r->voucher_id, 'voucher_number' => $r->voucher_number, 'date' => (string) $r->entry_date,
            'description' => (string) $r->description, 'category' => $r->category, 'account' => $r->account ?? '—', 'method' => $r->method,
            'amount' => (string) Money::of((string) $r->amount), 'has_receipt' => (bool) $r->has_receipt];
    }

    private function amountSql(string $kind): string
    {
        return $kind === 'expense' ? '(l.debit - l.credit)' : '(l.credit - l.debit)';
    }

    private function hasReceiptSql(): string
    {
        return "(EXISTS (SELECT 1 FROM attachments a WHERE a.company_id = e.company_id AND ((a.attachable_type = 'voucher' AND a.attachable_id = e.id)"
            ." OR (e.source_type = 'purchase_bills' AND a.attachable_type = 'purchase_bill' AND a.attachable_id = e.source_id))))";
    }

    /** @return array<int, string> ledger_id => amount, posted in the range */
    private function byLedger(int $c, string $kind, string $from, string $to): array
    {
        return $this->lines($c, $kind)->whereBetween('l.entry_date', [$from, $to])->groupBy('l.ledger_id')
            ->selectRaw('l.ledger_id, SUM('.$this->amountSql($kind).') v')->pluck('v', 'ledger_id')->all();
    }

    /** The 12 months ending with the period's month, with the monthly budget (expenses with budgets). */
    private function months(int $c, string $kind, string $to, ?Money $budget): array
    {
        $end = CarbonImmutable::parse($to)->startOfMonth();
        $start = $end->subMonths(11);
        $sums = $this->lines($c, $kind)->whereBetween('l.entry_date', [$start->toDateString(), $end->endOfMonth()->toDateString()])
            ->groupByRaw("DATE_FORMAT(l.entry_date, '%Y-%m')")->selectRaw("DATE_FORMAT(l.entry_date, '%Y-%m') m, SUM(".$this->amountSql($kind).') v')->pluck('v', 'm');
        $out = [];
        for ($m = $start; $m->lessThanOrEqualTo($end); $m = $m->addMonth()) {
            $out[] = ['month' => $m->format('Y-m'), 'label' => $m->format('M'), 'value' => (string) Money::of((string) ($sums[$m->format('Y-m')] ?? '0')),
                'budget' => $budget !== null ? (string) $budget : null];
        }

        return $out;
    }

    public static function monthsIn(string $from, string $to): int
    {
        $a = CarbonImmutable::parse($from)->startOfMonth();
        $b = CarbonImmutable::parse($to)->startOfMonth();

        return max(1, ($b->year - $a->year) * 12 + $b->month - $a->month + 1);
    }

    private static function buckets(string $from, string $to, string $by): array
    {
        $out = [];
        $d = CarbonImmutable::parse($from)->startOfMonth();
        $end = CarbonImmutable::parse($to);
        if ($by === 'quarter') {
            for ($q = $d->startOfQuarter(); $q->lte($end); $q = $q->addMonths(3)) {
                $out[] = ['key' => 'q_'.$q->year.'_'.$q->quarter, 'label' => 'Q'.$q->quarter.' '.$q->year, 'from' => $q->toDateString(), 'to' => $q->endOfQuarter()->toDateString()];
            }
        } else {
            for ($m = $d; $m->lte($end); $m = $m->addMonth()) {
                $out[] = ['key' => 'm_'.$m->format('Y_m'), 'label' => $m->format('M Y'), 'from' => $m->toDateString(), 'to' => $m->endOfMonth()->toDateString()];
            }
        }

        return $out;
    }

    private static function bucketKey(string $b, string $by): string
    {
        return $by === 'quarter' ? 'q_'.str_replace('-Q', '_', $b) : 'm_'.str_replace('-', '_', $b);
    }

    private static function share(Money $part, Money $whole): ?string
    {
        return $whole->isPositive() ? (string) $part->toBigDecimal()->multipliedBy(100)->dividedBy($whole->toBigDecimal(), 2, RoundingMode::HALF_UP) : null;
    }
}
