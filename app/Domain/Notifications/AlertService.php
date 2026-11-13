<?php

namespace App\Domain\Notifications;

use App\Domain\Access\PermissionResolver;
use App\Domain\Approvals\NotificationService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Taka;
use App\Mail\NotificationMail;
use App\Models\CompanyUser;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Scheduled alerts (11 §6; FR-013; backlog P14.1-BE-04), for the current company:
 *  - invoices that fell overdue yesterday → members with sales.view;
 *  - bills due in 3 days → members with purchases.view;
 *  - LC deadlines (PEP-489) 7 days ahead and on the day: LC expiry, latest shipment date, payment maturity, PAD/LTR
 *    loan due date → members with lc.view; each deadline once per LC or loan;
 *  - the daily summary e-mail → members who switched it on (notification_preferences, event daily_summary, e-mail),
 *    with only what their role may see (DEC-13: no balances without reports.financial).
 * Each document is alerted once: a rerun on the same day adds nothing in-app.
 */
final class AlertService
{
    public function __construct(private readonly NotificationService $notifications, private readonly PermissionResolver $permissions,
        private readonly CurrentCompany $current) {}

    /** Days before an LC deadline that the first alert goes out (a second one comes on the day). */
    public const LC_LEAD_DAYS = 7;

    private const LC_OPEN = ['opened', 'partly_shipped', 'documents_presented', 'payment_due'];

    /** @return array{overdue: int, due_soon: int, lc: int} documents alerted */
    public function run(CarbonImmutable $today): array
    {
        $c = $this->current->id();
        $overdue = $this->documents($c, 'sales_invoices', $today->subDay()->toDateString(), 'invoice_overdue', 'sales.view',
            fn ($d) => "{$d->number} is overdue",
            fn ($d) => "{$d->party} · was due ".CarbonImmutable::parse($d->due_date)->format('j M Y').' · '.Taka::format(Money::of((string) $d->balance_due)).' to collect',
            '/sales/invoices/');
        $soon = $this->documents($c, 'purchase_bills', $today->addDays(3)->toDateString(), 'bill_due_soon', 'purchases.view',
            fn ($d) => "{$d->number} is due in 3 days",
            fn ($d) => "{$d->party} · due ".CarbonImmutable::parse($d->due_date)->format('j M Y').' · '.Taka::format(Money::of((string) $d->balance_due)).' to pay',
            '/purchases/bills/');

        return ['overdue' => $overdue, 'due_soon' => $soon, 'lc' => $this->lcDeadlines($c, $today)];
    }

    /**
     * PEP-489: what an importer must not miss. A missed expiry means a new LC; a missed maturity or loan due date means
     * penalty interest. Alerts LEAD_DAYS before and on the day; a rerun never repeats one (same event, record and title).
     */
    private function lcDeadlines(int $c, CarbonImmutable $today): int
    {
        $soon = $today->addDays(self::LC_LEAD_DAYS)->toDateString();
        $days = [$today->toDateString() => 'today', $soon => 'in '.self::LC_LEAD_DAYS.' days'];
        $when = fn (string $date) => $days[CarbonImmutable::parse($date)->toDateString()];
        $alerts = [];
        $lcs = DB::table('letters_of_credit as l')->join('parties as p', 'p.id', '=', 'l.party_id')->where('l.company_id', $c)->whereIn('l.status', self::LC_OPEN)
            ->where(fn ($q) => $q->whereIn('l.expiry_date', array_keys($days))->orWhereIn('l.latest_shipment_date', array_keys($days))->orWhereIn('l.maturity_date', array_keys($days)))
            ->get(['l.id', 'l.lc_number', 'l.status', 'l.currency_code', 'l.amount', 'l.expiry_date', 'l.latest_shipment_date', 'l.maturity_date', 'p.name as party']);
        foreach ($lcs as $l) {
            $what = "{$l->party} · {$l->currency_code} {$l->amount}";
            if ($l->expiry_date && isset($days[(string) $l->expiry_date])) {
                $alerts[] = ['letters_of_credit', $l->id, "{$l->lc_number} expires {$when($l->expiry_date)}", "{$what} · expiry ".self::day($l->expiry_date).'. Ship and present documents before it, or ask the bank for an amendment.', "/lc/{$l->id}"];
            }
            if ($l->latest_shipment_date && in_array($l->status, ['opened', 'partly_shipped'], true) && isset($days[(string) $l->latest_shipment_date])) {
                $alerts[] = ['letters_of_credit', $l->id, "{$l->lc_number}: last shipment date {$when($l->latest_shipment_date)}", "{$what} · goods must be shipped by ".self::day($l->latest_shipment_date).'.', "/lc/{$l->id}"];
            }
            if ($l->maturity_date && isset($days[(string) $l->maturity_date])) {
                $alerts[] = ['letters_of_credit', $l->id, "{$l->lc_number}: payment matures {$when($l->maturity_date)}", "{$what} · the bank pays the supplier on ".self::day($l->maturity_date).'; keep the funds ready.', "/lc/{$l->id}"];
            }
        }
        $loans = DB::table('lc_loans as n')->join('letters_of_credit as l', 'l.id', '=', 'n.letter_of_credit_id')->where('n.company_id', $c)->where('n.status', 'open')
            ->whereIn('n.due_date', array_keys($days))->get(['n.id', 'n.loan_number', 'n.loan_kind', 'n.due_date', 'l.lc_number']);
        foreach ($loans as $n) {
            $kind = strtoupper((string) $n->loan_kind);
            $alerts[] = ['lc_loans', $n->id, "{$kind} loan {$n->loan_number} is due {$when($n->due_date)}", "For {$n->lc_number} · repay by ".self::day($n->due_date).' to avoid penalty interest.', "/lc/loans/{$n->id}"];
        }

        $holders = null;
        $sent = 0;
        foreach ($alerts as [$type, $id, $title, $body, $url]) {
            $done = DB::table('notifications')->where('company_id', $c)->where('event_key', 'lc_deadline')->where('link_type', $type)->where('link_id', $id)->where('title', $title)->exists();
            if ($done) {
                continue;
            }
            $holders ??= $this->notifications->holders('lc.view');
            $this->notifications->notify($c, $holders, 'lc_deadline', $title, $body, 'alerts', $type, (int) $id, $url);
            $sent++;
        }

        return $sent;
    }

    private static function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('j M Y');
    }

    private function documents(int $c, string $table, string $dueOn, string $event, string $permission, callable $title, callable $body, string $url): int
    {
        $docs = DB::table("{$table} as d")->join('parties as p', 'p.id', '=', 'd.party_id')->where('d.company_id', $c)->where('d.status', 'posted')
            ->where('d.balance_due', '>', 0)->whereDate('d.due_date', $dueOn)->orderBy('d.id')->get(['d.id', 'd.number', 'd.due_date', 'd.balance_due', 'p.name as party']);
        $done = DB::table('notifications')->where('company_id', $c)->where('event_key', $event)->where('link_type', $table)->whereIn('link_id', $docs->pluck('id'))
            ->pluck('link_id')->flip();
        $holders = null;
        $n = 0;
        foreach ($docs as $d) {
            if (isset($done[$d->id])) {
                continue;
            }
            $holders ??= $this->notifications->holders($permission);
            $this->notifications->notify($c, $holders, $event, $title($d), $body($d), 'alerts', $table, (int) $d->id, $url.$d->id);
            $n++;
        }

        return $n;
    }

    /** @return int e-mails queued (`$at`: only users whose chosen time it is; unset = 08:00) */
    public function dailySummary(CarbonImmutable $today, ?string $at = null): int
    {
        $c = $this->current->id();
        $company = $this->current->company();
        $ids = DB::table('notification_preferences')->where('company_id', $c)->where('event_key', 'daily_summary')->where('email', true)
            ->when($at, fn ($q) => $q->whereRaw("COALESCE(send_at, '08:00') = ?", [$at]))->pluck('user_id');
        $n = 0;
        foreach (CompanyUser::with('user')->where('status', 'active')->whereIn('user_id', $ids)->get() as $m) {
            if (! $m->user || $m->user->status !== 'active') {
                continue;
            }
            $lines = $this->summaryLines($c, $today, fn (string $p) => $this->permissions->allows($m, $p), (int) $m->user_id);
            Mail::to($m->user->email)->queue(new NotificationMail('Your Pluto summary for '.$today->format('l, j F'), $m->user->name, $lines,
                config('pluto.frontend_url').'/dashboard', (string) ($company->trading_name ?: $company->legal_name)));
            $n++;
        }

        return $n;
    }

    /**
     * The summary's lines for one member: only what the role may see.
     *
     * @param  callable(string): bool  $can
     * @return list<string>
     */
    public function summaryLines(int $c, CarbonImmutable $today, callable $can, int $userId): array
    {
        $yesterday = $today->subDay()->toDateString();
        $lines = ['Here is where things stand this morning ('.$today->format('j M Y').').', ''];
        if ($can('sales.view')) {
            $s = DB::table('sales_invoices')->where('company_id', $c)->where('status', 'posted')->whereDate('invoice_date', $yesterday)
                ->selectRaw('COUNT(*) n, COALESCE(SUM(total), 0) v')->first();
            $lines[] = "• Sales yesterday: {$s->n} ".Str::plural('invoice', (int) $s->n).', '.Taka::format(Money::of((string) $s->v)).'.';
        }
        if ($can('reports.financial')) {
            if ($can('banking.view')) {
                $cash = DB::table('money_accounts as a')->join('journal_lines as l', 'l.ledger_id', '=', 'a.ledger_id')->where('a.company_id', $c)->where('a.is_active', true)
                    ->where('l.is_posted', 1)->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) b')->value('b');
                $lines[] = '• Cash and bank: '.Taka::format(Money::of((string) $cash)).'.';
            }
            $o = DB::table('sales_invoices')->where('company_id', $c)->where('status', 'posted')->where('balance_due', '>', 0)->where('due_date', '<', $today->toDateString())
                ->selectRaw('COUNT(*) n, COALESCE(SUM(balance_due), 0) v')->first();
            $lines[] = "• Overdue invoices: {$o->n}, ".Taka::format(Money::of((string) $o->v)).' to collect.';
            if ($can('purchases.view')) {
                $b = DB::table('purchase_bills')->where('company_id', $c)->where('status', 'posted')->where('balance_due', '>', 0)
                    ->whereBetween('due_date', [$today->toDateString(), $today->addDays(6)->toDateString()])->selectRaw('COUNT(*) n, COALESCE(SUM(balance_due), 0) v')->first();
                $lines[] = "• Bills due this week: {$b->n}, ".Taka::format(Money::of((string) $b->v)).' to pay.';
            }
        }
        if ($can('inventory.view')) {
            $low = DB::table('product_warehouse_settings as s')->join('products as p', 'p.id', '=', 's.product_id')
                ->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_id', '=', 's.product_id')->on('b.warehouse_id', '=', 's.warehouse_id'))
                ->where('s.company_id', $c)->where('s.reorder_level', '>', 0)->where('p.is_active', true)->whereNull('p.deleted_at')
                ->whereRaw('COALESCE(b.qty_on_hand, 0) <= s.reorder_level')->count();
            $lines[] = "• Products at or below their reorder level: {$low}.";
        }
        if ($can('lc.view')) { // PEP-489
            $until = $today->addDays(self::LC_LEAD_DAYS)->toDateString();
            $n = DB::table('letters_of_credit')->where('company_id', $c)->whereIn('status', self::LC_OPEN)
                ->where(fn ($q) => $q->whereBetween('expiry_date', [$today->toDateString(), $until])->orWhereBetween('latest_shipment_date', [$today->toDateString(), $until])
                    ->orWhereBetween('maturity_date', [$today->toDateString(), $until]))->count()
                + DB::table('lc_loans')->where('company_id', $c)->where('status', 'open')->whereBetween('due_date', [$today->toDateString(), $until])->count();
            if ($n > 0) {
                $lines[] = '• LC deadlines in the next '.self::LC_LEAD_DAYS." days: {$n} (expiry, last shipment, maturity or PAD/LTR due).";
            }
        }
        $waiting = DB::table('approval_requests')->where('company_id', $c)->where('status', 'pending')->where('requested_by', '!=', $userId)
            ->pluck('required_permission')->filter(fn ($p) => $can((string) $p))->count();
        if ($waiting > 0) {
            $lines[] = "• Waiting for your approval: {$waiting}.";
        }
        if (count($lines) === 2) {
            $lines[] = 'Nothing new for your role today.';
        }

        return $lines;
    }
}
