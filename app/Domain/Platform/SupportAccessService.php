<?php

namespace App\Domain\Platform;

use App\Domain\Approvals\NotificationService;
use App\Domain\Shared\AuditLogger;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only support access (K17; PEP-485): a platform admin opens a customer's company for 15–240 minutes with a reason,
 * to help them without being a member. The company's Owners get a notification; the start and end go to its audit
 * log; ResolveCompany lets the admin in through a membership that only exists for the request (the Owner role's
 * permissions, so every page reads) and refuses every change with support_read_only.
 */
final class SupportAccessService
{
    public const MIN_MINUTES = 15;

    public const MAX_MINUTES = 240;

    public function __construct(private readonly AuditLogger $audit, private readonly NotificationService $notifications) {}

    /** @return array{company_id: int, company_name: string, reason: string, expires_at: string} SupportAccess */
    public function open(int $companyId, int $userId, string $reason, int $minutes = 60): array
    {
        $company = Company::find($companyId) ?? throw new ApiProblem('not_found');
        $minutes = max(self::MIN_MINUTES, min(self::MAX_MINUTES, $minutes));

        $grant = DB::transaction(function () use ($company, $userId, $reason, $minutes) {
            $this->closeOpen($company->id, $userId);
            $id = DB::table('support_access_grants')->insertGetId(['company_id' => $company->id, 'user_id' => $userId, 'reason' => $reason,
                'expires_at' => now()->addMinutes($minutes), 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            $grant = DB::table('support_access_grants')->find($id);
            $until = CarbonImmutable::parse($grant->expires_at, 'UTC')->setTimezone('Asia/Dhaka')->format('j M Y, H:i');
            $this->audit->record('support_access_started', null, null, ['reason' => $reason, 'until' => $grant->expires_at, 'minutes' => $minutes],
                userId: $userId, companyId: $company->id, type: 'support_access_grants');
            $this->notifications->notify($company->id, $this->owners($company->id), 'support_access', 'Pluto support opened your company (read-only)',
                "Reason: {$reason}. Until {$until} (Dhaka time). Nothing can be changed during the visit; it is in your audit log.");

            return $grant;
        });

        return self::resource($grant, $company);
    }

    public function end(int $companyId, int $userId): void
    {
        Company::find($companyId) ?? throw new ApiProblem('not_found');
        DB::transaction(function () use ($companyId, $userId) {
            if ($this->closeOpen($companyId, $userId) > 0) {
                $this->audit->record('support_access_ended', null, null, ['ended' => 'by the platform admin'], userId: $userId, companyId: $companyId, type: 'support_access_grants');
            }
        });
    }

    /** The grant letting this user read this company now, if any. */
    public function active(int $companyId, int $userId): ?object
    {
        return DB::table('support_access_grants')->where('company_id', $companyId)->where('user_id', $userId)
            ->whereNull('ended_at')->where('expires_at', '>', now())->orderByDesc('id')->first();
    }

    /** A membership for this request only (never saved): the company's Owner role, so every page can be read. */
    public function membership(Company $company, int $userId): CompanyUser
    {
        $role = Role::withoutGlobalScope('company')->where('company_id', $company->id)->where('slug', 'owner')->value('id');

        return (new CompanyUser)->forceFill(['company_id' => $company->id, 'user_id' => $userId, 'role_id' => $role, 'status' => 'active']);
    }

    /** @return array{company_id: int, company_name: string, reason: string, expires_at: string} */
    public static function resource(object $grant, Company $company): array
    {
        return ['company_id' => (int) $grant->company_id, 'company_name' => (string) ($company->trading_name ?: $company->legal_name), 'reason' => $grant->reason,
            'expires_at' => CarbonImmutable::parse($grant->expires_at, 'UTC')->toIso8601String()];
    }

    private function closeOpen(int $companyId, int $userId): int
    {
        return DB::table('support_access_grants')->where('company_id', $companyId)->where('user_id', $userId)->whereNull('ended_at')
            ->where('expires_at', '>', now())->update(['ended_at' => now(), 'updated_at' => now()]);
    }

    /** @return list<int> */
    private function owners(int $companyId): array
    {
        return DB::table('company_users as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.company_id', $companyId)->where('r.slug', 'owner')
            ->where('m.status', 'active')->pluck('m.user_id')->map(fn ($id) => (int) $id)->all();
    }
}
