<?php

namespace App\Queries;

use App\Domain\Accounting\AccountMapper;
use App\Domain\Lc\LcFinanceService;
use App\Domain\Lc\LcPostingService;
use App\Domain\Shared\Money;
use App\Models\BankFacility;
use App\Models\LcLoan;
use App\Models\LetterOfCredit;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * LC reads (M1 overview, M2 list, M6 document matrix, M7 cost summary, M8 reports; FR-090–FR-099; 06 §12.10;
 * backlog P16.1-BE-01/03/08/09). Money figures come from the LC tables and the journal lines tagged with the LC, so
 * the cost summary always agrees with the ledgers. Planned taka = foreign amount × planning rate (an estimate).
 */
final class LcQuery
{
    public const GROUPS = ['pipeline' => ['draft', 'awaiting_bank'], 'active' => ['opened', 'partly_shipped', 'documents_presented', 'payment_due'],
        'closed' => ['closed', 'cancelled', 'expired']];

    public const REPORTS = ['register', 'status', 'outstanding', 'expiry', 'documents', 'amendments', 'charges', 'cost', 'loans', 'exposure'];

    public function list(array $f): Builder
    {
        $q = LetterOfCredit::query()->with('party', 'issuingBank', 'purchaseOrder');
        if (! empty($f['group']) && isset(self::GROUPS[$f['group']])) {
            $q->whereIn('status', self::GROUPS[$f['group']]);
        }
        foreach (['bank_account_id' => 'issuing_bank_account_id', 'party_id' => 'party_id'] as $k => $col) {
            if (! empty($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        if (($f['q'] ?? '') !== '') {
            $t = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('lc_number', 'like', $t)->orWhere('bank_reference', 'like', $t)->orWhere('goods_description', 'like', $t)
                ->orWhereHas('party', fn ($p) => $p->where('name', 'like', $t)));
        }
        if (($f['attention'] ?? '') === 'true') {
            $soon = CarbonImmutable::now('Asia/Dhaka')->addDays(15)->toDateString();
            $q->whereIn('status', self::GROUPS['active'])->where(fn ($w) => $w->where('expiry_date', '<=', $soon)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('lc_documents')->whereColumn('lc_documents.letter_of_credit_id', 'letters_of_credit.id')->whereIn('lc_documents.status', ['missing', 'discrepant']))
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('lc_charges')->whereColumn('lc_charges.letter_of_credit_id', 'letters_of_credit.id')->where('lc_charges.status', 'unpaid')->where('lc_charges.posting_status', 'posted')));
        }
        $sort = $f['sort'] ?? '-created';
        $col = ['created' => 'id', 'number' => 'lc_number', 'expiry' => 'expiry_date', 'amount' => 'amount'][ltrim($sort, '-')] ?? 'id';

        return $q->orderBy($col, str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id', 'desc');
    }

    private static function planned(object $lc): Money
    {
        return Money::of(Money::of((string) $lc->amount)->toBigDecimal()->multipliedBy((string) $lc->planning_rate)->toScale(2, RoundingMode::HalfUp));
    }

    /** M1: KPIs, pipeline by status, by bank, deadlines, alerts and the active LCs. */
    public function overview(int $c): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $lcs = DB::table('letters_of_credit as l')->leftJoin('parties as p', 'p.id', '=', 'l.party_id')->leftJoin('money_accounts as m', 'm.id', '=', 'l.issuing_bank_account_id')
            ->where('l.company_id', $c)->get(['l.*', 'p.name as party', 'm.name as bank']);
        $active = $lcs->whereIn('status', self::GROUPS['active']);
        $paid = DB::table('lc_payments')->where('company_id', $c)->where('status', 'posted')->whereIn('payment_kind', ['sight_payment', 'usance_acceptance'])
            ->groupBy('letter_of_credit_id')->selectRaw('letter_of_credit_id, SUM(amount_foreign) f')->pluck('f', 'letter_of_credit_id');
        $outstanding = Money::zero();
        $rows = [];
        foreach ($active as $l) {
            $left = Money::of((string) $l->amount)->minus((string) ($paid[$l->id] ?? '0'));
            $outstanding = $outstanding->plus(Money::of($left->toBigDecimal()->multipliedBy((string) $l->planning_rate)->toScale(2, RoundingMode::HalfUp)));
            $rows[] = ['kind' => 'lc', 'id' => $l->id, 'lc_number' => $l->lc_number, 'party' => $l->party, 'bank' => $l->bank, 'status' => $l->status,
                'currency_code' => $l->currency_code, 'amount' => (string) Money::of((string) $l->amount), 'outstanding_foreign' => (string) $left,
                'expiry_date' => $l->expiry_date, 'days_to_expiry' => $l->expiry_date ? (int) CarbonImmutable::parse($today)->diffInDays(CarbonImmutable::parse($l->expiry_date), false) : null,
                'url' => "/lc/{$l->id}"];
        }
        usort($rows, fn ($a, $b) => [$a['expiry_date'] ?? '9999'] <=> [$b['expiry_date'] ?? '9999']);
        $margin = app(AccountMapper::class)->ledgerFor('lc_margin')->id;
        $git = app(AccountMapper::class)->ledgerFor('goods_in_transit')->id;
        $bal = fn (int $ledger) => (string) Money::of((string) DB::table('journal_lines')->where('company_id', $c)->where('ledger_id', $ledger)->whereNotNull('letter_of_credit_id')
            ->where('is_posted', 1)->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) b')->value('b'));
        $deadlines = [];
        foreach ($active as $l) {
            foreach (['expiry_date' => 'Expiry', 'latest_shipment_date' => 'Latest shipment', 'maturity_date' => 'Maturity'] as $col => $label) {
                if ($l->{$col} && $l->{$col} >= $today && $l->{$col} <= CarbonImmutable::parse($today)->addDays(30)->toDateString()) {
                    $deadlines[] = ['lc_id' => $l->id, 'lc_number' => $l->lc_number, 'kind' => $label, 'date' => $l->{$col}, 'url' => "/lc/{$l->id}"];
                }
            }
        }
        $openLoans = DB::table('lc_loans')->where('company_id', $c)->where('status', 'open')->get(['id', 'letter_of_credit_id', 'loan_number', 'due_date']);
        foreach ($openLoans as $loan) {
            if ($loan->due_date <= CarbonImmutable::parse($today)->addDays(30)->toDateString()) {
                $deadlines[] = ['lc_id' => $loan->letter_of_credit_id, 'lc_number' => $loan->loan_number, 'kind' => $loan->due_date < $today ? 'Loan overdue' : 'Loan due', 'date' => $loan->due_date, 'url' => "/lc/loans/{$loan->id}"];
            }
        }
        $finance = app(LcFinanceService::class);
        $loansOutstanding = Money::sum(LcLoan::whereIn('id', $openLoans->pluck('id'))->get()->map(fn ($l) => $finance->outstanding($l))->all());
        usort($deadlines, fn ($a, $b) => $a['date'] <=> $b['date']);
        $byBank = $active->groupBy('bank')->map(fn ($g, $bank) => ['bank' => $bank ?: 'Not chosen', 'count' => $g->count(),
            'planned_bdt' => (string) Money::sum($g->map(fn ($l) => self::planned($l))->all())])->values()->all();
        $recent = DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.company_id', $c)
            ->whereIn('a.auditable_type', ['letters_of_credit', 'lc_charges', 'lc_payments', 'lc_documents', 'landed_cost_allocations', 'lc_loans', 'bank_facilities'])->orderByDesc('a.id')->limit(8)
            ->get(['a.action', 'a.auditable_type', 'a.auditable_id', 'a.created_at', 'u.name as user'])
            ->map(fn ($a) => ['action' => $a->action, 'type' => $a->auditable_type, 'id' => $a->auditable_id, 'user' => $a->user, 'at' => CarbonImmutable::parse($a->created_at)->toIso8601String()])->all();
        $missingDocs = DB::table('lc_documents as d')->join('letters_of_credit as l', 'l.id', '=', 'd.letter_of_credit_id')->where('l.company_id', $c)
            ->whereIn('l.status', self::GROUPS['active'])->whereIn('d.status', ['missing', 'discrepant'])->count();
        $unpaid = DB::table('lc_charges')->where('company_id', $c)->where('status', 'unpaid')->where('posting_status', 'posted')->count();

        return [
            'title' => 'LC overview', 'period' => ['as_of' => $today], 'basis' => 'Planned taka at each LC\'s planning rate; balances from the LC-tagged ledger lines',
            'columns' => [['key' => 'lc_number', 'label' => 'LC', 'type' => 'code'], ['key' => 'party', 'label' => 'Supplier', 'type' => 'text'], ['key' => 'status', 'label' => 'Status', 'type' => 'text'],
                ['key' => 'outstanding_foreign', 'label' => 'Outstanding', 'type' => 'amount'], ['key' => 'expiry_date', 'label' => 'Expiry', 'type' => 'date']],
            'rows' => $rows,
            'totals' => ['active' => $active->count(), 'pipeline' => $lcs->whereIn('status', self::GROUPS['pipeline'])->count(),
                'active_planned_bdt' => (string) Money::sum($active->map(fn ($l) => self::planned($l))->all()), 'outstanding_bdt' => (string) $outstanding,
                'margin_held' => $bal($margin), 'goods_in_transit' => $bal($git), 'expiring_15' => $active->filter(fn ($l) => $l->expiry_date && $l->expiry_date <= CarbonImmutable::parse($today)->addDays(15)->toDateString())->count(),
                'documents_attention' => $missingDocs, 'unpaid_charges' => $unpaid, 'loans_open' => $openLoans->count(), 'loans_outstanding' => (string) $loansOutstanding,
                'loans_due_7' => $openLoans->filter(fn ($l) => $l->due_date <= CarbonImmutable::parse($today)->addDays(7)->toDateString())->count()],
            'checks' => [],
            'meta' => ['pipeline' => collect(['draft', 'awaiting_bank', 'opened', 'partly_shipped', 'documents_presented', 'payment_due', 'expired', 'closed'])
                ->map(fn ($s) => ['status' => $s, 'count' => $lcs->where('status', $s)->count()])->all(), 'by_bank' => $byBank, 'deadlines' => array_slice($deadlines, 0, 10), 'recent' => $recent],
        ];
    }

    /** M6: document status across open LCs, and the follow-up list. */
    public function matrix(int $c): array
    {
        $docs = DB::table('lc_documents as d')->join('letters_of_credit as l', 'l.id', '=', 'd.letter_of_credit_id')->leftJoin('parties as p', 'p.id', '=', 'l.party_id')
            ->where('l.company_id', $c)->whereNotIn('l.status', ['closed', 'cancelled', 'draft'])->orderBy('l.lc_number')->orderBy('d.id')
            ->get(['d.id', 'd.name', 'd.status', 'd.due_date', 'd.is_required', 'l.id as lc_id', 'l.lc_number', 'p.name as party']);
        $rows = [];
        foreach ($docs->groupBy('lc_id') as $lcId => $g) {
            $count = fn (array $s) => $g->whereIn('status', $s)->count();
            $rows[] = ['kind' => 'lc', 'id' => $lcId, 'lc_number' => $g->first()->lc_number, 'party' => $g->first()->party, 'received' => $count(['received', 'not_required']),
                'pending' => $count(['pending', 'not_yet_due']), 'missing' => $count(['missing']), 'discrepant' => $count(['discrepant']), 'total' => $g->count(),
                'documents' => $g->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'status' => $d->status])->values()->all(), 'url' => "/lc/{$lcId}?tab=documents"];
        }
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $follow = $docs->filter(fn ($d) => $d->is_required && (in_array($d->status, ['missing', 'discrepant'], true) || ($d->status === 'pending' && $d->due_date && $d->due_date <= $today)))
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'status' => $d->status, 'due_date' => $d->due_date, 'lc_number' => $d->lc_number, 'lc_id' => $d->lc_id])->values()->all();

        return ['title' => 'LC documents', 'period' => ['as_of' => $today], 'basis' => 'Opened LCs; received includes documents marked not required',
            'columns' => [['key' => 'lc_number', 'label' => 'LC', 'type' => 'code'], ['key' => 'party', 'label' => 'Supplier', 'type' => 'text'], ['key' => 'received', 'label' => 'Received', 'type' => 'qty'],
                ['key' => 'pending', 'label' => 'Pending', 'type' => 'qty'], ['key' => 'missing', 'label' => 'Missing', 'type' => 'qty'], ['key' => 'discrepant', 'label' => 'Discrepant', 'type' => 'qty'],
                ['key' => 'total', 'label' => 'Total', 'type' => 'qty']],
            'rows' => $rows, 'totals' => ['lcs' => count($rows), 'follow_up' => count($follow)], 'checks' => [], 'meta' => ['follow_up' => $follow]];
    }

    /** M7: what the goods cost — supplier payments, each capitalised charge, landed cost per unit. */
    public function costSummary(LetterOfCredit $lc): array
    {
        $posting = app(LcPostingService::class);
        $charges = DB::table('lc_charges')->where('letter_of_credit_id', $lc->id)->where('posting_status', 'posted')->where('charge_type', '!=', 'margin_deposit')
            ->groupBy('charge_type', 'include_in_landed_cost')->selectRaw('charge_type, include_in_landed_cost, SUM(amount) a')->orderBy('charge_type')->get();
        $bills = DB::table('purchase_bills')->where('letter_of_credit_id', $lc->id)->where('status', 'posted');
        $goods = Money::of((string) (clone $bills)->sum('total'));
        $qty = (string) DB::table('purchase_bill_lines as bl')->join('purchase_bills as b', 'b.id', '=', 'bl.purchase_bill_id')->where('b.letter_of_credit_id', $lc->id)->where('b.status', 'posted')->sum('bl.quantity');
        $fx = Money::of(LcPostingService::position($lc)['fx_total']);
        $rows = [['kind' => 'line', 'key' => 'goods', 'label' => 'Goods (import bills at their rate)', 'amount' => (string) $goods, 'landed' => true]];
        $capital = Money::zero();
        foreach ($charges as $ch) {
            $rows[] = ['kind' => 'line', 'key' => $ch->charge_type, 'label' => ucfirst(str_replace('_', ' ', $ch->charge_type)), 'amount' => (string) Money::of((string) $ch->a), 'landed' => (bool) $ch->include_in_landed_cost];
            if ($ch->include_in_landed_cost) {
                $capital = $capital->plus((string) $ch->a);
            }
        }
        $landed = $goods->plus($capital);
        $rows[] = ['kind' => 'subtotal', 'key' => 'landed_cost', 'label' => 'Landed cost', 'amount' => (string) $landed, 'landed' => true];
        $rows[] = ['kind' => 'line', 'key' => 'fx', 'label' => 'Exchange difference (in profit and loss)', 'amount' => (string) $fx, 'landed' => false];
        $perUnit = BigDecimal::of($qty ?: '0')->isPositive() ? (string) $landed->toBigDecimal()->dividedBy($qty, 4, RoundingMode::HalfUp) : null;

        return ['title' => "Cost of {$lc->lc_number}", 'period' => ['as_of' => CarbonImmutable::now('Asia/Dhaka')->toDateString()], 'basis' => 'Posted bills and charges; the margin deposit is not a cost',
            'columns' => [['key' => 'label', 'label' => 'Item', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount (৳)', 'type' => 'amount']],
            'rows' => $rows, 'totals' => ['goods' => (string) $goods, 'charges_capitalised' => (string) $capital, 'landed_cost' => (string) $landed, 'quantity' => $qty, 'per_unit' => $perUnit,
                'allocated' => LcPostingService::position($lc)['landed_cost_allocated'], 'unallocated' => (string) $posting->tagged($lc->id, 'goods_in_transit')],
            'checks' => [['key' => 'goods_in_transit', 'ok' => $posting->tagged($lc->id, 'goods_in_transit')->isZero(), 'message' => $posting->tagged($lc->id, 'goods_in_transit')->isZero()
                ? 'Every capitalised charge is in the goods' : '৳ '.$posting->tagged($lc->id, 'goods_in_transit').' waits in goods in transit (1404) to be allocated']],
            'meta' => ['lc_id' => $lc->id, 'allocations' => DB::table('landed_cost_allocations')->where('letter_of_credit_id', $lc->id)->orderBy('id')
                ->get(['id', 'purchase_bill_id', 'allocation_date', 'method', 'total_amount', 'status'])->map(fn ($a) => (array) $a)->all()]];
    }

    /** M8: the eight LC reports (05 GET /reports/lc/{report}). */
    public function report(int $c, string $report, array $f): array
    {
        $from = $f['date_from'] ?? null;
        $to = $f['date_to'] ?? CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $lcs = DB::table('letters_of_credit as l')->leftJoin('parties as p', 'p.id', '=', 'l.party_id')->leftJoin('money_accounts as m', 'm.id', '=', 'l.issuing_bank_account_id')
            ->where('l.company_id', $c)->when($f['bank_account_id'] ?? null, fn ($q, $v) => $q->where('l.issuing_bank_account_id', $v))
            ->select('l.*', 'p.name as party', 'm.name as bank');
        $col = fn (string $key, string $label, string $type = 'text') => ['key' => $key, 'label' => $label, 'type' => $type];
        $paid = DB::table('lc_payments')->where('company_id', $c)->where('status', 'posted')->whereIn('payment_kind', ['sight_payment', 'usance_acceptance'])
            ->groupBy('letter_of_credit_id')->selectRaw('letter_of_credit_id, SUM(amount_foreign) f, SUM(amount_bdt) b')->get()->keyBy('letter_of_credit_id');
        $base = ['period' => ['from' => $from ?? '2000-01-01', 'to' => $to], 'checks' => []];
        $row = fn ($l) => ['kind' => 'lc', 'id' => $l->id, 'lc_number' => $l->lc_number, 'party' => $l->party, 'bank' => $l->bank, 'status' => $l->status, 'currency_code' => $l->currency_code,
            'amount' => (string) Money::of((string) $l->amount), 'planned_bdt' => (string) self::planned($l), 'opened_on' => $l->opened_on, 'expiry_date' => $l->expiry_date, 'url' => "/lc/{$l->id}"];

        switch ($report) {
            case 'register':
                $list = (clone $lcs)->when($from, fn ($q) => $q->where('l.created_at', '>=', $from))->where('l.created_at', '<=', $to.' 23:59:59')->orderBy('l.lc_number')->get();

                return $base + ['title' => 'LC register', 'basis' => 'Every LC raised in the period',
                    'columns' => [$col('lc_number', 'LC', 'code'), $col('party', 'Supplier'), $col('bank', 'Bank'), $col('status', 'Status'), $col('currency_code', 'Currency'),
                        $col('amount', 'Amount', 'amount'), $col('planned_bdt', 'Planned (৳)', 'amount'), $col('opened_on', 'Opened', 'date'), $col('expiry_date', 'Expiry', 'date')],
                    'rows' => $list->map($row)->all(), 'totals' => ['planned_bdt' => (string) Money::sum($list->map(fn ($l) => self::planned($l))->all()), 'count' => $list->count()], 'meta' => null];
            case 'status':
                $segment = $f['segment'] ?? 'active';
                $list = (clone $lcs)->whereIn('l.status', $segment === 'expired' ? ['expired'] : ($segment === 'closed' ? ['closed', 'cancelled'] : self::GROUPS['active']))->orderBy('l.expiry_date')->get();

                return $base + ['title' => $segment === 'expired' ? 'Expired LCs' : ($segment === 'closed' ? 'Closed LCs' : 'Active LCs'), 'basis' => 'Current status',
                    'columns' => [$col('lc_number', 'LC', 'code'), $col('party', 'Supplier'), $col('status', 'Status'), $col('amount', 'Amount', 'amount'), $col('expiry_date', 'Expiry', 'date')],
                    'rows' => $list->map($row)->all(), 'totals' => ['count' => $list->count()], 'meta' => ['segment' => $segment]];
            case 'outstanding':
                $list = (clone $lcs)->whereIn('l.status', self::GROUPS['active'])->orderBy('l.lc_number')->get();
                $rows = $list->map(function ($l) use ($row, $paid) {
                    $done = Money::of((string) ($paid[$l->id]->f ?? '0'));
                    $left = Money::of((string) $l->amount)->minus($done);

                    return $row($l) + ['paid_foreign' => (string) $done, 'outstanding_foreign' => (string) $left,
                        'outstanding_bdt' => (string) Money::of($left->toBigDecimal()->multipliedBy((string) $l->planning_rate)->toScale(2, RoundingMode::HalfUp))];
                })->all();

                return $base + ['title' => 'Outstanding LC commitments', 'basis' => 'LC amount less what is paid, at each planning rate',
                    'columns' => [$col('lc_number', 'LC', 'code'), $col('party', 'Supplier'), $col('currency_code', 'Currency'), $col('amount', 'Amount', 'amount'), $col('paid_foreign', 'Paid', 'amount'),
                        $col('outstanding_foreign', 'Outstanding', 'amount'), $col('outstanding_bdt', 'Outstanding (৳)', 'amount')],
                    'rows' => $rows, 'totals' => ['outstanding_bdt' => (string) Money::sum(array_map(fn ($r) => Money::of($r['outstanding_bdt']), $rows))], 'meta' => null];
            case 'expiry':
                $until = CarbonImmutable::parse($to)->addDays(60)->toDateString();
                $list = (clone $lcs)->whereIn('l.status', self::GROUPS['active'])->whereNotNull('l.expiry_date')->where('l.expiry_date', '<=', $until)->orderBy('l.expiry_date')->get();

                return $base + ['title' => 'Upcoming expiry', 'basis' => 'Active LCs expiring within 60 days',
                    'columns' => [$col('lc_number', 'LC', 'code'), $col('party', 'Supplier'), $col('expiry_date', 'Expiry', 'date'), $col('days', 'Days left', 'qty'), $col('amount', 'Amount', 'amount')],
                    'rows' => $list->map(fn ($l) => $row($l) + ['days' => (int) CarbonImmutable::parse($to)->diffInDays(CarbonImmutable::parse($l->expiry_date), false)])->all(),
                    'totals' => ['count' => $list->count()], 'meta' => null];
            case 'documents':
                return $this->matrix($c) + ['title' => 'Document completion'];
            case 'amendments':
                $list = DB::table('lc_amendments as a')->join('letters_of_credit as l', 'l.id', '=', 'a.letter_of_credit_id')->where('l.company_id', $c)
                    ->when($from, fn ($q) => $q->where('a.requested_on', '>=', $from))->where('a.requested_on', '<=', $to)->orderByDesc('a.requested_on')
                    ->get(['a.*', 'l.lc_number', 'l.id as lc_id']);

                return $base + ['title' => 'Amendment history', 'basis' => 'Amendments requested in the period',
                    'columns' => [$col('lc_number', 'LC', 'code'), $col('amendment_no', 'No.', 'qty'), $col('requested_on', 'Requested', 'date'), $col('status', 'Status'),
                        $col('changes', 'Changes'), $col('bank_fee', 'Bank fee (৳)', 'amount')],
                    'rows' => $list->map(fn ($a) => ['kind' => 'amendment', 'id' => $a->id, 'lc_number' => $a->lc_number, 'amendment_no' => (int) $a->amendment_no, 'requested_on' => $a->requested_on,
                        'status' => $a->status, 'bank_fee' => (string) Money::of((string) $a->bank_fee), 'url' => "/lc/{$a->lc_id}?tab=amendments",
                        'changes' => DB::table('lc_amendment_changes')->where('lc_amendment_id', $a->id)->get()->map(fn ($ch) => str_replace('_', ' ', $ch->field_key).': '.($ch->old_value ?? '—').' → '.$ch->new_value)->implode('; ')])->all(),
                    'totals' => ['bank_fee' => (string) Money::of((string) $list->sum('bank_fee'))], 'meta' => null];
            case 'charges':
                $list = DB::table('lc_charges as ch')->join('letters_of_credit as l', 'l.id', '=', 'ch.letter_of_credit_id')->where('l.company_id', $c)->where('ch.posting_status', 'posted')
                    ->when($from, fn ($q) => $q->where('ch.charge_date', '>=', $from))->where('ch.charge_date', '<=', $to)->orderBy('ch.charge_date')->get(['ch.*', 'l.lc_number']);
                $payments = DB::table('lc_payments as pm')->join('letters_of_credit as l', 'l.id', '=', 'pm.letter_of_credit_id')->where('l.company_id', $c)->where('pm.status', 'posted')
                    ->when($from, fn ($q) => $q->where('pm.payment_date', '>=', $from))->where('pm.payment_date', '<=', $to)->orderBy('pm.payment_date')->get(['pm.*', 'l.lc_number']);
                $rows = [...$list->map(fn ($ch) => ['kind' => 'charge', 'id' => $ch->id, 'date' => $ch->charge_date, 'lc_number' => $ch->lc_number, 'what' => ucfirst(str_replace('_', ' ', $ch->charge_type)),
                    'status' => $ch->status, 'amount' => (string) Money::of((string) $ch->amount)])->all(),
                    ...$payments->map(fn ($p) => ['kind' => 'payment', 'id' => $p->id, 'date' => $p->payment_date, 'lc_number' => $p->lc_number,
                        'what' => ucfirst(str_replace('_', ' ', $p->payment_kind))." {$p->amount_foreign} at {$p->bank_rate}", 'status' => 'paid', 'amount' => (string) Money::of((string) $p->amount_bdt)])->all()];
                usort($rows, fn ($a, $b) => $a['date'] <=> $b['date']);

                return $base + ['title' => 'Charges and payments', 'basis' => 'Posted LC charges and payments in the period',
                    'columns' => [$col('date', 'Date', 'date'), $col('lc_number', 'LC', 'code'), $col('what', 'What'), $col('status', 'Status'), $col('amount', 'Amount (৳)', 'amount')],
                    'rows' => $rows, 'totals' => ['amount' => (string) Money::sum(array_map(fn ($r) => Money::of($r['amount']), $rows))], 'meta' => null];
            case 'loans': // P16 LC finance: PAD/LTR loans with what is owed today
                $finance = app(LcFinanceService::class);
                $loans = LcLoan::where('company_id', $c)->when($f['bank_account_id'] ?? null, fn ($q, $v) => $q->where('money_account_id', $v))
                    ->when(($f['segment'] ?? 'open') !== 'all', fn ($q) => $q->where('status', 'open'))->orderBy('due_date')->orderBy('id')->get();
                $rows = $loans->map(fn ($loan) => ['kind' => 'loan', 'url' => "/lc/loans/{$loan->id}"] + $finance->loanResource($loan, false))->all();
                $sum = fn (string $k) => (string) Money::sum(array_map(fn ($r) => Money::of($r[$k]), $rows));

                return $base + ['title' => 'PAD/LTR loans', 'basis' => 'Outstanding principal and the interest due today (days from the last interest date × rate ÷ day count)',
                    'columns' => [$col('loan_number', 'Loan', 'code'), $col('bank_name', 'Bank'), $col('loan_kind', 'Kind'), $col('principal', 'Principal (৳)', 'amount'),
                        $col('outstanding_principal', 'Outstanding (৳)', 'amount'), $col('interest_rate_pct', 'Rate %', 'qty'), $col('interest_due_today', 'Interest due (৳)', 'amount'),
                        $col('due_date', 'Due', 'date'), $col('status', 'Status')],
                    'rows' => $rows, 'totals' => ['principal' => $sum('principal'), 'outstanding_principal' => $sum('outstanding_principal'), 'interest_due_today' => $sum('interest_due_today'),
                        'count' => count($rows)], 'meta' => ['segment' => $f['segment'] ?? 'open']];
            case 'exposure': // P16 LC finance: limits, use and room per bank (BR-066)
                $finance = app(LcFinanceService::class);
                $rows = BankFacility::where('company_id', $c)->when($f['bank_account_id'] ?? null, fn ($q, $v) => $q->where('money_account_id', $v))->orderBy('id')->get()
                    ->map(fn ($fa) => ['kind' => 'bank_facility', 'url' => '/lc/banks'] + $finance->facilityResource($fa))->all();
                $sum = fn (string $k) => (string) Money::sum(array_map(fn ($r) => Money::of($r[$k]), $rows));

                return $base + ['title' => 'Bank exposure', 'basis' => 'LC use = planned taka still to pay on open LCs + open acceptances; loans = outstanding PAD/LTR principal',
                    'columns' => [$col('bank_name', 'Bank'), $col('lc_limit', 'LC limit (৳)', 'amount'), $col('lc_utilised', 'LC used (৳)', 'amount'), $col('lc_available', 'LC available (৳)', 'amount'),
                        $col('loan_limit', 'Loan limit (৳)', 'amount'), $col('loans_outstanding', 'Loans (৳)', 'amount'), $col('loan_available', 'Loan available (৳)', 'amount'),
                        $col('limit_action', 'Over limit')],
                    'rows' => $rows, 'totals' => ['lc_utilised' => $sum('lc_utilised'), 'loans_outstanding' => $sum('loans_outstanding'), 'acceptances_outstanding' => $sum('acceptances_outstanding')],
                    'meta' => null];
            default: // cost
                $list = (clone $lcs)->whereNotIn('l.status', ['draft', 'cancelled'])->orderBy('l.lc_number')->get();
                $rows = $list->map(function ($l) {
                    $s = $this->costSummary(LetterOfCredit::findOrFail($l->id))['totals'];

                    return ['kind' => 'lc', 'id' => $l->id, 'lc_number' => $l->lc_number, 'party' => $l->party, 'goods' => $s['goods'], 'charges' => $s['charges_capitalised'],
                        'landed_cost' => $s['landed_cost'], 'per_unit' => $s['per_unit'], 'url' => "/lc/{$l->id}/cost"];
                })->all();

                return $base + ['title' => 'Import cost summary', 'basis' => 'Posted import bills plus capitalised charges, per LC',
                    'columns' => [$col('lc_number', 'LC', 'code'), $col('party', 'Supplier'), $col('goods', 'Goods (৳)', 'amount'), $col('charges', 'Charges (৳)', 'amount'),
                        $col('landed_cost', 'Landed cost (৳)', 'amount'), $col('per_unit', 'Per unit (৳)', 'amount')],
                    'rows' => $rows, 'totals' => ['landed_cost' => (string) Money::sum(array_map(fn ($r) => Money::of($r['landed_cost']), $rows))], 'meta' => null];
        }
    }
}
