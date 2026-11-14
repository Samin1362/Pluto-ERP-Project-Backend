<?php

namespace App\Domain\Platform;

use App\Domain\Access\MemberService;
use App\Domain\Approvals\NotificationService;
use App\Domain\Shared\AuditLogger;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Owner access (K17; PEP-496; owner decision 14 Nov 2026): the software owner sells Pluto to many companies and must
 * be able to work in any of them. A platform admin joins a company as a real member with its Owner role, so
 * permissions, approvals, posting, created_by and the audit log work exactly as for any Owner, under the admin's own
 * name. The company's Owners are told, the start and end are in its audit log, the Members list marks the admin as
 * Pluto support (company_users.joined_via = platform_admin) and the membership uses none of the customer's seats.
 * The read-only support view (SupportAccessService) is a different thing and is not changed by this.
 */
final class OwnerAccessService
{
    public function __construct(private readonly MemberService $members, private readonly AuditLogger $audit, private readonly NotificationService $notifications) {}

    /** @return array{company_id: int, company_name: string, role: string, joined_at: string, already_member: bool} OwnerAccess */
    public function join(int $companyId, User $admin, string $reason): array
    {
        return DB::transaction(function () use ($companyId, $admin, $reason) {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
            if ($company->status !== 'active') {
                throw new ApiProblem('invalid_transition', "{$company->legal_name} is {$company->status}; reactivate it first, then open it.");
            }
            $role = Role::withoutGlobalScope('company')->where('company_id', $company->id)->where('slug', 'owner')->firstOrFail();
            $before = $this->membership($company->id, $admin->id);
            // Already an active Owner here (a second click, or the admin's own company): nothing to change or announce.
            if ($before && $before->status === 'active' && (int) $before->role_id === (int) $role->id) {
                return self::resource($before, $company, true);
            }
            $old = $before ? ['status' => $before->status, 'role_id' => (int) $before->role_id] : null;
            $m = $this->members->enrol($company->id, $admin->id, (int) $role->id, 'platform_admin');
            $this->audit->record('platform_owner_access_started', $m, $old, ['status' => 'active', 'role' => $role->name, 'reason' => $reason, 'by' => 'platform'],
                userId: $admin->id, companyId: $company->id, type: 'company_users');
            $this->notifications->notify($company->id, $this->otherOwners($company->id, $admin->id), 'owner_access', "Pluto support ({$admin->name}) joined your company as Owner",
                "Pluto support ({$admin->name}) joined your company as Owner. Reason: {$reason}. Every change they make is in your audit log under their name.");

            return self::resource($m, $company, $before !== null);
        });
    }

    /** Deactivates the admin's own membership; refused while they are the company's only active Owner. */
    public function leave(int $companyId, User $admin): void
    {
        DB::transaction(function () use ($companyId, $admin) {
            $company = Company::query()->whereKey($companyId)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
            $m = $this->membership($company->id, $admin->id);
            if (! $m || $m->status !== 'active') {
                return;
            }
            if ($m->role?->slug === 'owner' && $this->otherOwners($company->id, $admin->id) === []) {
                throw new ApiProblem('last_owner', "You are the only active Owner of {$company->legal_name}; make someone else Owner first.");
            }
            $m->forceFill(['status' => 'deactivated', 'deactivated_at' => now(), 'deactivated_by' => $admin->id])->save();
            $this->audit->record('platform_owner_access_ended', $m, ['status' => 'active'], ['status' => 'deactivated', 'ended' => 'by the platform admin'],
                userId: $admin->id, companyId: $company->id, type: 'company_users');
        });
    }

    private function membership(int $companyId, int $userId): ?CompanyUser
    {
        return CompanyUser::withoutGlobalScope('company')->with('role')->where('company_id', $companyId)->where('user_id', $userId)->lockForUpdate()->first();
    }

    /** @return array{company_id: int, company_name: string, role: string, joined_at: string, already_member: bool} */
    private static function resource(CompanyUser $m, Company $company, bool $already): array
    {
        return ['company_id' => (int) $company->id, 'company_name' => (string) ($company->trading_name ?: $company->legal_name), 'role' => 'owner',
            'joined_at' => ($m->joined_at ?? now())->toIso8601String(), 'already_member' => $already];
    }

    /** @return list<int> the company's active Owners other than this user */
    private function otherOwners(int $companyId, int $userId): array
    {
        return DB::table('company_users as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.company_id', $companyId)->where('r.slug', 'owner')
            ->where('m.status', 'active')->where('m.user_id', '!=', $userId)->pluck('m.user_id')->map(fn ($id) => (int) $id)->all();
    }
}
