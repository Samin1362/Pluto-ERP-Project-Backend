<?php

namespace App\Domain\Parties;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Mail\PaymentReminderMail;
use App\Models\Party;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Payment reminders (PEP-516): e-mail a customer the list of their overdue invoices (posted, balance due, past the due
 * date) with their statement attached; by hand from the customer's page or by the daily job when the company switched
 * automatic reminders on. At most one a week per customer, whichever way it was sent; every reminder is logged.
 */
class PaymentReminderService
{
    public const MIN_DAYS_BETWEEN = 7;

    public function __construct(private readonly CurrentCompany $current, private readonly PartyStatementService $statements) {}

    /** Overdue invoices of a customer, oldest due first; $minDaysLate = days past the due date (0 = any day after it). */
    public function overdue(Party $party, int $minDaysLate = 0): array
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->startOfDay();

        return DB::table('sales_invoices')->where('company_id', $this->current->id())->where('party_id', $party->id)->where('status', 'posted')
            ->where('balance_due', '>', 0)->where('due_date', '<', $today->subDays($minDaysLate)->toDateString())
            ->orderBy('due_date')->orderBy('id')->get(['id', 'number', 'invoice_date', 'due_date', 'total', 'balance_due'])
            ->map(fn ($i) => ['number' => $i->number, 'invoice_date' => (string) $i->invoice_date, 'due_date' => (string) $i->due_date, 'total' => (string) $i->total,
                'balance_due' => (string) $i->balance_due, 'days_late' => (int) CarbonImmutable::parse($i->due_date, 'Asia/Dhaka')->startOfDay()->diffInDays($today)])->all();
    }

    public function sentRecently(Party $party): bool
    {
        return DB::table('payment_reminders')->where('company_id', $this->current->id())->where('party_id', $party->id)
            ->where('sent_at', '>', now()->subDays(self::MIN_DAYS_BETWEEN))->exists();
    }

    /** Sends one reminder and returns it (PaymentReminder); throws the 404/422/409 problems when it cannot be sent. */
    public function send(Party $party, string $trigger = 'manual', ?int $userId = null, int $minDaysLate = 0): array
    {
        if ($party->party_type !== 'customer') {
            throw new ApiProblem('not_found');
        }
        if (! $party->email) {
            throw new ApiProblem('party_has_no_email', detail: "Add an e-mail address to {$party->name} to send reminders.");
        }
        $invoices = $this->overdue($party, $minDaysLate);
        if (! $invoices) {
            throw new ApiProblem('no_overdue_invoices', detail: "{$party->name} has no overdue invoices.");
        }
        if ($this->sentRecently($party)) {
            throw new ApiProblem('reminder_sent_recently', detail: 'Customers get at most one reminder a week.');
        }

        $total = Money::zero();
        foreach ($invoices as $i) {
            $total = $total->plus(Money::of($i['balance_due']));
        }
        $company = $this->current->company();
        $from = min(array_column($invoices, 'invoice_date'));
        $to = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        Mail::to($party->email)->queue(new PaymentReminderMail($party->name, $company->trading_name ?: $company->legal_name, $invoices, (string) $total,
            base64_encode($this->statements->pdf($party, $from, $to, true)), "statement-{$party->code}.pdf"));

        $id = DB::table('payment_reminders')->insertGetId(['company_id' => $company->id, 'party_id' => $party->id, 'sent_at' => now(), 'trigger' => $trigger,
            'email' => $party->email, 'invoices_count' => count($invoices), 'overdue_amount' => (string) $total, 'sent_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        app(AuditLogger::class)->record('payment_reminder_sent', $party, null, ['trigger' => $trigger, 'invoices' => array_column($invoices, 'number'),
            'overdue_amount' => (string) $total], userId: $userId, companyId: $company->id);

        return $this->find($id);
    }

    public function find(int $id): array
    {
        $r = DB::table('payment_reminders as r')->leftJoin('users as u', 'u.id', '=', 'r.sent_by')->where('r.company_id', $this->current->id())->where('r.id', $id)
            ->first(['r.*', 'u.name as user_name']);

        return self::row($r);
    }

    /** @return array{data: list<array>, meta: array} */
    public function list(Party $party, int $perPage, int $page): array
    {
        $q = DB::table('payment_reminders as r')->leftJoin('users as u', 'u.id', '=', 'r.sent_by')->where('r.company_id', $this->current->id())->where('r.party_id', $party->id);
        $total = (clone $q)->count();
        $rows = $q->orderByDesc('r.sent_at')->orderByDesc('r.id')->forPage($page, $perPage)->get(['r.*', 'u.name as user_name']);

        return ['data' => $rows->map(fn ($r) => self::row($r))->all(),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))]];
    }

    private static function row(object $r): array
    {
        return ['id' => (int) $r->id, 'sent_at' => CarbonImmutable::parse($r->sent_at)->toIso8601String(), 'trigger' => $r->trigger, 'email' => $r->email,
            'invoices_count' => (int) $r->invoices_count, 'overdue_amount' => (string) $r->overdue_amount,
            'sent_by' => $r->sent_by ? ['id' => (int) $r->sent_by, 'name' => $r->user_name] : null];
    }
}
