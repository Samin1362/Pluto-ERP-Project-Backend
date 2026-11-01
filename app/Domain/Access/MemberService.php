<?php

namespace App\Domain\Access;

use App\Domain\Shared\AuditLogger;
use App\Mail\InvitationMail;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Users and invitations (K8–K10, S3; FR-006–FR-008; BR-059 seats, BR-060 roles, BR-061 deactivation; R-18 branches;
 * backlog P15.1-BE-04/05).
 *  - Only an Owner gives or takes the Owner role, and a company always keeps one active Owner (`last_owner`).
 *  - Deactivating a member ends all their sign-ins at once (their next call is 401); their pending approvals stay.
 *  - An invitation e-mails a 7-day link (APP_FRONTEND_URL/invite/<token>); only the SHA-256 of the token is stored.
 *    Accepting creates the user (name and password) or links an existing one, copies the branches, and signs in.
 *  - Seats (companies.seat_limit) are checked on invite, accept and reactivate.
 */
final class MemberService
{
    public const INVITE_DAYS = 7;

    public function __construct(private readonly CurrentCompany $current, private readonly PermissionResolver $permissions, private readonly AuditLogger $audit,
        private readonly AccountService $account) {}

    /** Member (05 schema); `$detail` adds permissions and recent activity (K10). */
    public function resource(CompanyUser $m, bool $detail = false): array
    {
        $m->loadMissing('user', 'role');
        $lastSeen = DB::table('user_sessions')->where('user_id', $m->user_id)->max('last_seen_at');
        $out = ['id' => $m->id, 'user_id' => $m->user_id, 'name' => $m->user->name, 'email' => $m->user->email, 'role_id' => $m->role_id, 'role_name' => $m->role->name,
            'status' => $m->status, 'last_active_at' => $lastSeen ? CarbonImmutable::parse($lastSeen)->toIso8601String() : $m->user->last_login_at?->toIso8601String(),
            'two_factor_enabled' => $m->user->two_factor_confirmed_at !== null,
            'branch_ids' => DB::table('company_user_branches')->where('company_user_id', $m->id)->orderBy('branch_id')->pluck('branch_id')->map(fn ($v) => (int) $v)->all(),
            'version' => (int) $m->lock_version];
        if ($detail) {
            $out['permissions'] = $this->permissions->forMembership($m);
            $out['recent_activity'] = AuditLog::with('user')->where('company_id', $m->company_id)->where('user_id', $m->user_id)->orderByDesc('created_at')->orderByDesc('id')
                ->limit(10)->get()->map(fn (AuditLog $a) => ['id' => $a->id, 'action' => $a->action, 'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
                    'old_values' => $a->old_values, 'new_values' => $a->new_values, 'ip_address' => $a->ip_address, 'created_at' => $a->created_at?->toIso8601String()])->all();
        }

        return $out;
    }

    public function update(CompanyUser $actor, int $id, array $in, int $version): CompanyUser
    {
        return DB::transaction(function () use ($actor, $id, $in, $version) {
            $m = CompanyUser::with('role')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ((int) $m->lock_version !== $version) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $m->lock_version]);
            }
            if (isset($in['role_id']) && (int) $in['role_id'] !== (int) $m->role_id) {
                $to = Role::findOrFail($in['role_id']);
                if (($to->slug === 'owner' || $m->role->slug === 'owner') && $actor->role?->slug !== 'owner') {
                    throw new ApiProblem('permission_denied', 'Only an Owner can give or take the Owner role.');
                }
                if ($m->role->slug === 'owner') {
                    $this->assertNotLastOwner($m);
                }
                $old = ['role' => $m->role->name];
                $m->forceFill(['role_id' => $to->id])->save();
                $this->audit->record('role_changed', $m, $old, ['role' => $to->name], type: 'company_users');
            }
            if (array_key_exists('branch_ids', $in)) {
                DB::table('company_user_branches')->where('company_user_id', $m->id)->delete();
                DB::table('company_user_branches')->insert(array_map(fn ($b) => ['company_user_id' => $m->id, 'branch_id' => $b], array_values(array_unique($in['branch_ids'] ?? []))));
                $m->touch();
                $this->audit->record('branches_changed', $m, null, ['branch_ids' => $in['branch_ids']], type: 'company_users');
            }

            return $m->refresh();
        });
    }

    public function deactivate(CompanyUser $actor, int $id): CompanyUser
    {
        return DB::transaction(function () use ($actor, $id) {
            $m = CompanyUser::with('role')->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($m->user_id === $actor->user_id) {
                throw new ApiProblem('validation_failed', errors: ['id' => ['You cannot deactivate yourself']]);
            }
            if ($m->role->slug === 'owner') {
                if ($actor->role?->slug !== 'owner') {
                    throw new ApiProblem('permission_denied', 'Only an Owner can deactivate an Owner.');
                }
                $this->assertNotLastOwner($m);
            }
            if ($m->status === 'deactivated') {
                return $m;
            }
            $m->forceFill(['status' => 'deactivated', 'deactivated_at' => now(), 'deactivated_by' => $actor->user_id])->save();
            $this->account->revokeFamilies($m->user_id, 'member_deactivated');
            $this->audit->record('deactivated', $m, ['status' => 'active'], ['status' => 'deactivated'], type: 'company_users');

            return $m;
        });
    }

    public function reactivate(int $id): CompanyUser
    {
        return DB::transaction(function () use ($id) {
            $m = CompanyUser::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($m->status !== 'deactivated') {
                return $m;
            }
            $this->assertSeat($m->company_id);
            $m->forceFill(['status' => 'active', 'deactivated_at' => null, 'deactivated_by' => null])->save();
            $this->audit->record('reactivated', $m, ['status' => 'deactivated'], ['status' => 'active'], type: 'company_users');

            return $m;
        });
    }

    private function assertNotLastOwner(CompanyUser $m): void
    {
        $others = CompanyUser::where('status', 'active')->whereKeyNot($m->id)->whereHas('role', fn ($q) => $q->where('slug', 'owner'))->count();
        if ($others === 0) {
            throw new ApiProblem('last_owner', 'The company must keep at least one active Owner; make someone else Owner first.');
        }
    }

    private function assertSeat(int $companyId): void
    {
        $limit = (int) Company::whereKey($companyId)->value('seat_limit');
        if (CompanyService::seatsUsed($companyId) >= $limit) {
            throw new ApiProblem('seat_limit_reached', "All {$limit} seats are in use; deactivate someone or ask for more seats.");
        }
    }

    /** Invitation (05 schema). */
    public static function invitation(object $i): array
    {
        $status = $i->accepted_at ? 'accepted' : ($i->revoked_at ? 'revoked' : (now()->greaterThan($i->expires_at) ? 'expired' : 'pending'));

        return ['id' => $i->id, 'email' => $i->email, 'name' => $i->name, 'role_id' => $i->role_id, 'expires_at' => CarbonImmutable::parse($i->expires_at)->toIso8601String(), 'status' => $status];
    }

    /** Open invitations of the company (K8), newest first. */
    public function invitations(): array
    {
        return DB::table('user_invitations')->where('company_id', $this->current->id())->whereNull('accepted_at')->whereNull('revoked_at')
            ->orderByDesc('created_at')->get()->map(fn ($i) => self::invitation($i))->all();
    }

    public function invite(array $in): array
    {
        $c = $this->current->id();
        $email = Str::lower(trim($in['email']));

        return DB::transaction(function () use ($c, $email, $in) {
            Company::whereKey($c)->lockForUpdate()->first();
            $member = CompanyUser::whereHas('user', fn ($q) => $q->where('email', $email))->first();
            if ($member && $member->status !== 'deactivated') {
                throw new ApiProblem('duplicate', "{$email} is already a member of this company.");
            }
            if ($member) {
                throw new ApiProblem('duplicate', "{$email} was a member; reactivate them on their user page instead.");
            }
            $open = DB::table('user_invitations')->where('company_id', $c)->where('email', $email)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->exists();
            if ($open) {
                throw new ApiProblem('duplicate', "{$email} already has an open invitation; resend it instead.");
            }
            $this->assertSeat($c);
            $role = Role::findOrFail($in['role_id']);
            [$id, $token] = $this->newInvitation($c, $email, $in['name'] ?? null, $role->id, $in['branch_ids'] ?? []);
            $this->send($id, $token);
            $this->audit->record('invited', null, null, ['email' => $email, 'role' => $role->name], type: 'user_invitations');

            return self::invitation(DB::table('user_invitations')->find($id));
        });
    }

    /** @return array{0: int, 1: string} invitation id and the raw token (only ever e-mailed) */
    private function newInvitation(int $c, string $email, ?string $name, int $roleId, array $branches): array
    {
        $token = Str::random(48);
        $id = DB::table('user_invitations')->insertGetId(['company_id' => $c, 'email' => $email, 'name' => $name, 'role_id' => $roleId, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::INVITE_DAYS), 'created_by' => auth()->id(), 'updated_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
        if ($branches !== []) {
            DB::table('user_invitation_branches')->insert(array_map(fn ($b) => ['user_invitation_id' => $id, 'branch_id' => $b], array_values(array_unique($branches))));
        }

        return [$id, $token];
    }

    /** POST /invitations/{id}/resend: a new token and a new 7-day expiry. */
    public function resend(int $id): array
    {
        return DB::transaction(function () use ($id) {
            $i = DB::table('user_invitations')->where('company_id', $this->current->id())->where('id', $id)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
            if ($i->accepted_at || $i->revoked_at) {
                throw new ApiProblem('invalid_transition', 'This invitation was already '.($i->accepted_at ? 'accepted' : 'revoked').'.');
            }
            $token = Str::random(48);
            DB::table('user_invitations')->where('id', $id)->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(self::INVITE_DAYS),
                'updated_by' => auth()->id(), 'updated_at' => now()]);
            $this->send($id, $token);
            $this->audit->record('invitation_resent', null, null, ['email' => $i->email], type: 'user_invitations');

            return self::invitation(DB::table('user_invitations')->find($id));
        });
    }

    public function revoke(int $id): void
    {
        $n = DB::table('user_invitations')->where('company_id', $this->current->id())->where('id', $id)->whereNull('accepted_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_by' => auth()->id(), 'updated_at' => now()]);
        if ($n === 0) {
            throw new ApiProblem('not_found');
        }
        $this->audit->record('invitation_revoked', null, null, ['invitation_id' => $id], type: 'user_invitations');
    }

    private function send(int $id, string $token): void
    {
        $i = DB::table('user_invitations as i')->join('companies as c', 'c.id', '=', 'i.company_id')->join('roles as r', 'r.id', '=', 'i.role_id')->where('i.id', $id)
            ->first(['i.email', 'i.name', 'i.expires_at', 'c.legal_name', 'c.trading_name', 'r.name as role']);
        $inviter = auth()->user()?->name ?? 'Someone';
        Mail::to($i->email)->queue(new InvitationMail($i->name, $inviter, (string) ($i->trading_name ?: $i->legal_name), $i->role,
            config('pluto.frontend_url').'/invite/'.$token, CarbonImmutable::parse($i->expires_at)->setTimezone('Asia/Dhaka')->format('j M Y')));
    }

    /** The open invitation of a raw token, or 404 / 410. */
    private function open(string $token, bool $lock = false): object
    {
        $q = DB::table('user_invitations')->where('token_hash', hash('sha256', $token));
        $i = ($lock ? $q->lockForUpdate() : $q)->first() ?? throw new ApiProblem('not_found');
        if ($i->accepted_at || $i->revoked_at || now()->greaterThan($i->expires_at)) {
            throw new ApiProblem('gone', $i->accepted_at ? 'This invitation was already accepted; sign in instead.' : 'This invitation has expired or was withdrawn; ask for a new one.');
        }

        return $i;
    }

    /** InvitationPublic (S3): what the invitee sees before accepting. */
    public function show(string $token): array
    {
        $i = $this->open($token);
        $c = Company::findOrFail($i->company_id);

        return ['company_name' => (string) ($c->trading_name ?: $c->legal_name), 'role_name' => (string) DB::table('roles')->where('id', $i->role_id)->value('name'),
            'email' => $i->email, 'expires_at' => CarbonImmutable::parse($i->expires_at)->toIso8601String(), 'existing_user' => User::where('email', $i->email)->exists()];
    }

    /**
     * POST /invitations/{token}/accept: a new user sets name and password; an existing user confirms with their
     * password. Returns the user to sign in, and the company joined.
     *
     * @return array{user: User, company_id: int}
     */
    public function accept(string $token, array $in): array
    {
        return DB::transaction(function () use ($token, $in) {
            $i = $this->open($token, true);
            $this->current->set(Company::findOrFail($i->company_id), null);
            $user = User::where('email', $i->email)->first();
            if ($user) {
                if ($user->status !== 'active' || ! Hash::check((string) ($in['password'] ?? ''), $user->password_hash)) {
                    throw new ApiProblem('validation_failed', errors: ['password' => ['Enter the password of your existing Pluto account']]);
                }
            } else {
                $errors = array_filter(['name' => empty($in['name']) ? ['Enter your name'] : null,
                    'password' => empty($in['password']) ? ['Choose a password'] : null]);
                if (! $errors) {
                    $v = Validator::make($in, ['password' => ['confirmed', AccountService::passwordRule()]],
                        ['password.confirmed' => 'The two passwords do not match', 'password.min' => 'Use at least 10 characters',
                            'password.uncompromised' => 'This password has appeared in a data breach; choose another']);
                    $errors = $v->fails() ? $v->errors()->toArray() : [];
                }
                if ($errors) {
                    throw new ApiProblem('validation_failed', errors: $errors);
                }
                $user = User::forceCreate(['name' => $in['name'], 'email' => $i->email, 'password_hash' => Hash::make($in['password']), 'status' => 'active',
                    'email_verified_at' => now(), 'password_changed_at' => now()]);
            }
            $limit = (int) Company::whereKey($i->company_id)->lockForUpdate()->value('seat_limit');
            // The invitation itself holds a seat until accepted; the member replaces it.
            if (CompanyService::seatsUsed($i->company_id) > $limit) {
                throw new ApiProblem('seat_limit_reached', 'The company has no free seat; ask an Owner to free one.');
            }
            $existing = CompanyUser::where('user_id', $user->id)->first();
            if ($existing && $existing->status === 'active') {
                throw new ApiProblem('duplicate', 'You are already a member of this company; sign in.');
            }
            $m = $existing ?? new CompanyUser;
            $m->forceFill(['company_id' => $i->company_id, 'user_id' => $user->id, 'role_id' => $i->role_id, 'status' => 'active', 'joined_at' => now(),
                'deactivated_at' => null, 'deactivated_by' => null])->save();
            $branches = DB::table('user_invitation_branches')->where('user_invitation_id', $i->id)->pluck('branch_id')->all();
            DB::table('company_user_branches')->where('company_user_id', $m->id)->delete();
            DB::table('company_user_branches')->insert(array_map(fn ($b) => ['company_user_id' => $m->id, 'branch_id' => $b], $branches));
            DB::table('user_invitations')->where('id', $i->id)->update(['accepted_at' => now(), 'updated_at' => now()]);
            $this->audit->record('invitation_accepted', $m, null, ['email' => $i->email], userId: $user->id, companyId: $i->company_id, type: 'company_users');

            return ['user' => $user, 'company_id' => (int) $i->company_id];
        });
    }
}
