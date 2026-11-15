<?php

namespace App\Domain\Platform;

use App\Domain\Approvals\NotificationService;
use App\Domain\Shared\AuditLogger;
use App\Models\Company;
use App\Support\Problem\ApiProblem;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Subscription tracking (PEP-503; roadmap A7, owner request 15 Nov 2026): the software owner records what each
 * customer pays and until when. A company without paid_until is not billed and is never suspended for payment.
 * Every day `pluto:subscriptions:check` reminds the company's Owners 7 days before and on the due day, mails the
 * platform admins a summary, and suspends a company `grace_days` after paid_until (billing_suspended = 1); recording
 * a payment for such a company reopens it. Money is BDT, BigDecimal (BR-010).
 */
final class SubscriptionService
{
    public const DUE_SOON_DAYS = 7;

    public function __construct(private readonly AuditLogger $audit, private readonly NotificationService $notifications) {}

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::now('Asia/Dhaka')->toDateString());
    }

    /** @return array{price: ?string, paid_until: ?string, grace_days: int, status: string, days_left: ?int} CompanyBilling */
    public static function billing(object $c): array
    {
        $until = $c->paid_until ? CarbonImmutable::parse(substr((string) $c->paid_until, 0, 10)) : null;
        $left = $until ? (int) self::today()->diffInDays($until, false) : null;
        $status = match (true) {
            (bool) $c->billing_suspended && $c->status === 'suspended' => 'suspended',
            $until === null => 'not_billed',
            $left < 0 => 'overdue',
            $left <= self::DUE_SOON_DAYS => 'due_soon',
            default => 'paid',
        };

        return ['price' => $c->billing_price === null ? null : (string) BigDecimal::of($c->billing_price)->toScale(2), 'paid_until' => $until?->toDateString(),
            'grace_days' => (int) $c->grace_days, 'status' => $status, 'days_left' => $left];
    }

    /** POST /platform/companies/{id}/billing: price, paid until (null = not billed) and grace days. */
    public function setTerms(int $companyId, array $in): void
    {
        DB::transaction(function () use ($companyId, $in) {
            $c = Company::query()->whereKey($companyId)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
            $old = ['price' => $c->billing_price, 'paid_until' => $c->paid_until ? substr((string) $c->paid_until, 0, 10) : null, 'grace_days' => (int) $c->grace_days];
            $set = [];
            if (array_key_exists('price', $in)) {
                $set['billing_price'] = $in['price'] === null ? null : (string) BigDecimal::of($in['price'])->toScale(2);
            }
            if (array_key_exists('paid_until', $in)) {
                $set['paid_until'] = $in['paid_until'];
            }
            if (array_key_exists('grace_days', $in)) {
                $set['grace_days'] = (int) $in['grace_days'];
            }
            $c->forceFill($set)->save();
            $this->reopenIfPaid($c);
            $this->audit->record('billing_changed', $c, $old, ['price' => $c->billing_price, 'paid_until' => $c->paid_until ? substr((string) $c->paid_until, 0, 10) : null,
                'grace_days' => (int) $c->grace_days, 'by' => 'platform'], companyId: $c->id, type: 'companies');
        });
    }

    /** POST /platform/companies/{id}/payments: paid_until = later of (paid_until, paid_on) + months. */
    public function record(int $companyId, array $in, int $userId): array
    {
        return DB::transaction(function () use ($companyId, $in, $userId) {
            $c = Company::query()->whereKey($companyId)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
            $paidOn = CarbonImmutable::parse($in['paid_on']);
            $from = $c->paid_until ? CarbonImmutable::parse(substr((string) $c->paid_until, 0, 10)) : null;
            $base = $from && $from->greaterThan($paidOn) ? $from : $paidOn;
            $until = $base->addMonthsNoOverflow((int) $in['months']);
            $id = DB::table('subscription_payments')->insertGetId(['company_id' => $c->id, 'paid_on' => $paidOn->toDateString(),
                'amount' => (string) BigDecimal::of($in['amount'])->toScale(2), 'months' => (int) $in['months'], 'paid_until' => $until->toDateString(),
                'method' => $in['method'], 'reference' => $in['reference'] ?? null, 'note' => $in['note'] ?? null,
                'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $c->forceFill(['paid_until' => $until->toDateString()])->save();
            $reopened = $this->reopenIfPaid($c);
            $row = DB::table('subscription_payments')->find($id);
            $this->audit->record('subscription_paid', $c, ['paid_until' => $from?->toDateString()], ['amount' => $row->amount, 'months' => (int) $row->months,
                'paid_until' => $row->paid_until, 'method' => $row->method, 'reopened' => $reopened, 'by' => 'platform'], userId: $userId, companyId: $c->id, type: 'companies');
            $this->notifications->notify($c->id, self::owners($c->id), 'subscription_paid', 'Pluto payment received, thank you',
                "৳ {$row->amount} for {$row->months} month(s). Your subscription is paid until ".CarbonImmutable::parse($row->paid_until)->format('j M Y').'.'.($reopened ? ' Your company is open again.' : ''));

            return self::payment($row);
        });
    }

    /** @return list<array<string, mixed>> SubscriptionPayment[] */
    public function payments(int $companyId): array
    {
        Company::query()->whereKey($companyId)->exists() || throw new ApiProblem('not_found');

        return DB::table('subscription_payments as p')->leftJoin('users as u', 'u.id', '=', 'p.created_by')->where('p.company_id', $companyId)
            ->orderByDesc('p.paid_on')->orderByDesc('p.id')->get(['p.*', 'u.name as recorded_by'])->map(fn ($r) => self::payment($r))->all();
    }

    /**
     * The daily run: reminders 7 days before and on the due day, suspension grace_days after it.
     *
     * @return array{reminded: int, suspended: list<string>, due_soon: list<string>, overdue: list<string>}
     */
    public function check(?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $out = ['reminded' => 0, 'suspended' => [], 'due_soon' => [], 'overdue' => []];
        foreach (DB::table('companies')->whereNotNull('paid_until')->where('status', 'active')->orderBy('id')->get() as $c) {
            $until = CarbonImmutable::parse(substr((string) $c->paid_until, 0, 10));
            $left = (int) $today->diffInDays($until, false);
            $name = $c->trading_name ?: $c->legal_name;
            if ($left === self::DUE_SOON_DAYS || $left === 0) {
                $this->notifications->notify($c->id, self::owners($c->id), 'subscription_due', $left === 0 ? 'Your Pluto subscription is due today' : 'Your Pluto subscription is due in 7 days',
                    'Paid until '.$until->format('j M Y').'. Please pay to keep using Pluto without interruption; the company is paused '.((int) $c->grace_days).' days after the due date.');
                $out['reminded']++;
            }
            if ($left < 0 && -$left > (int) $c->grace_days) {
                DB::transaction(function () use ($c, $until) {
                    $row = Company::query()->whereKey($c->id)->lockForUpdate()->first();
                    $row->forceFill(['status' => 'suspended', 'billing_suspended' => true])->save();
                    $this->audit->record('suspended', $row, ['status' => 'active'], ['status' => 'suspended', 'reason' => 'Subscription unpaid since '.$until->toDateString(), 'by' => 'billing'],
                        companyId: $row->id, type: 'companies');
                });
                $out['suspended'][] = "{$name} (paid until {$until->format('j M Y')})";
            } elseif ($left < 0) {
                $out['overdue'][] = "{$name} (".(-$left).' days, paid until '.$until->format('j M Y').')';
            } elseif ($left <= self::DUE_SOON_DAYS) {
                $out['due_soon'][] = "{$name} (in {$left} days)";
            }
        }

        return $out;
    }

    /** A company suspended for payment and now paid up again is reopened. */
    private function reopenIfPaid(Company $c): bool
    {
        $until = $c->paid_until ? CarbonImmutable::parse(substr((string) $c->paid_until, 0, 10)) : null;
        if (! $c->billing_suspended || ($until && $until->lessThan(self::today()->subDays((int) $c->grace_days)))) {
            return false;
        }
        $c->forceFill(['status' => 'active', 'billing_suspended' => false])->save();
        $this->audit->record('reactivated', $c, ['status' => 'suspended'], ['status' => 'active', 'reason' => 'Subscription paid', 'by' => 'billing'], companyId: $c->id, type: 'companies');

        return true;
    }

    /** @return list<int> */
    private static function owners(int $companyId): array
    {
        return DB::table('company_users as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.company_id', $companyId)->where('r.slug', 'owner')
            ->where('m.status', 'active')->where('m.joined_via', 'member')->pluck('m.user_id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return array<string, mixed> SubscriptionPayment */
    private static function payment(object $r): array
    {
        return ['id' => (int) $r->id, 'paid_on' => substr((string) $r->paid_on, 0, 10), 'amount' => (string) BigDecimal::of($r->amount)->toScale(2), 'months' => (int) $r->months,
            'paid_until' => substr((string) $r->paid_until, 0, 10), 'method' => $r->method, 'reference' => $r->reference, 'note' => $r->note,
            'recorded_by' => $r->recorded_by ?? null, 'created_at' => $r->created_at ? CarbonImmutable::parse($r->created_at, 'UTC')->toIso8601String() : null];
    }
}
