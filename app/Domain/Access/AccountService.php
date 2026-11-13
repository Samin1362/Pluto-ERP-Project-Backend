<?php

namespace App\Domain\Access;

use App\Domain\Shared\AuditLogger;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * The signed-in user's own account (K12–K14, S2; FR-003, FR-009, FR-013, FR-086; BR-061; backlog P15.1-BE-07/08):
 * password change and reset, signed-in devices, appearance and notification preferences.
 *
 * A sign-in is one refresh-token family (rotation adds rows to it): the device list shows each live family once, and
 * revoking one revokes the family, so its access tokens stop at the next call. A password change or reset changes the
 * password version (`pwv`), which ends every other access token; the change keeps only the current family.
 */
final class AccountService
{
    /** Notification events a user can switch (K12), with their default channels. */
    public const EVENTS = [
        'approval_requested' => ['label' => 'A voucher needs my approval', 'in_app' => true, 'email' => false],
        'approval_approved' => ['label' => 'My voucher was approved', 'in_app' => true, 'email' => false],
        'approval_rejected' => ['label' => 'My voucher was rejected', 'in_app' => true, 'email' => false],
        'invoice_overdue' => ['label' => 'An invoice falls overdue', 'in_app' => true, 'email' => false],
        'bill_due_soon' => ['label' => 'A bill is due in 3 days', 'in_app' => true, 'email' => false],
        'lc_deadline' => ['label' => 'An LC deadline is near (expiry, last shipment, payment maturity, PAD/LTR loan due)', 'in_app' => true, 'email' => false],
        'daily_summary' => ['label' => 'Daily summary', 'in_app' => false, 'email' => false],
    ];

    public const SUMMARY_TIMES = ['07:30', '08:00', '09:00'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** NFR-011: at least 10 characters; not in a known breach when the check is on. */
    public static function passwordRule(): Password
    {
        $rule = Password::min(10)->max(200);

        return config('pluto.passwords.breach_check') ? $rule->uncompromised() : $rule;
    }

    public function changePassword(User $user, string $current, string $new, string $keepFamily): void
    {
        if (! Hash::check($current, $user->password_hash)) {
            throw new ApiProblem('validation_failed', errors: ['current_password' => ['Your current password is not right']]);
        }
        if (Hash::check($new, $user->password_hash)) {
            throw new ApiProblem('validation_failed', errors: ['password' => ['Choose a password different from the current one']]);
        }
        DB::transaction(function () use ($user, $new, $keepFamily) {
            $user->forceFill(['password_hash' => Hash::make($new), 'password_changed_at' => now()])->save();
            $this->revokeFamilies($user->id, 'password_changed', exceptFamily: $keepFamily);
            $this->audit->record('password_changed', $user, userId: $user->id, type: 'users');
        });
    }

    /** POST /auth/password/forgot: e-mails a 60-minute link when the e-mail belongs to an active user; silent otherwise. */
    public function forgot(string $email): void
    {
        $user = User::where('email', Str::lower(trim($email)))->first();
        if (! $user || $user->status !== 'active') {
            return; // no enumeration (R-12): the caller always gets 202
        }
        $token = Str::random(64);
        DB::table('password_reset_tokens')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
        DB::table('password_reset_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes((int) config('pluto.passwords.reset_minutes')), 'created_at' => now(), 'updated_at' => now()]);
        $link = config('pluto.frontend_url').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
        Mail::to($user->email)->queue(new PasswordResetMail($user->name, $link, (int) config('pluto.passwords.reset_minutes')));
        $this->audit->record('password_reset_requested', $user, userId: $user->id, type: 'users');
    }

    /** POST /auth/password/reset: one use, before it expires; every session ends and a lockout is lifted. */
    public function reset(string $email, string $token, string $password): void
    {
        DB::transaction(function () use ($email, $token, $password) {
            $user = User::where('email', Str::lower(trim($email)))->first();
            $row = $user ? DB::table('password_reset_tokens')->where('user_id', $user->id)->where('token_hash', hash('sha256', $token))->lockForUpdate()->first() : null;
            if (! $row || $row->used_at !== null || now()->greaterThan($row->expires_at) || $user->status !== 'active') {
                throw new ApiProblem('validation_failed', errors: ['token' => ['This reset link has expired or was already used; ask for a new one']]);
            }
            DB::table('password_reset_tokens')->where('id', $row->id)->update(['used_at' => now(), 'updated_at' => now()]);
            $user->forceFill(['password_hash' => Hash::make($password), 'password_changed_at' => now(), 'failed_login_count' => 0, 'locked_until' => null])->save();
            $this->revokeFamilies($user->id, 'password_reset');
            $this->audit->record('password_reset', $user, userId: $user->id, type: 'users');
        });
    }

    /** Session (05 schema) list: one row per live sign-in, newest first; `current` marks this request's sign-in. */
    public function sessions(int $userId, string $currentFamily): array
    {
        return UserSession::where('user_id', $userId)->whereNull('revoked_at')->whereNull('replaced_by_id')->where('expires_at', '>', now())
            ->orderByDesc('last_seen_at')->orderByDesc('id')->get()
            ->map(fn (UserSession $s) => ['id' => $s->id, 'device_label' => $s->device_label, 'user_agent' => $s->user_agent, 'ip_address' => $s->ip_address,
                'last_seen_at' => $s->last_seen_at?->toIso8601String(), 'current' => $s->family_id === $currentFamily])->all();
    }

    public function revokeSession(int $userId, int $sessionId): void
    {
        $s = UserSession::where('user_id', $userId)->whereKey($sessionId)->whereNull('revoked_at')->first() ?? throw new ApiProblem('not_found');
        UserSession::where('family_id', $s->family_id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'revoked_reason' => 'revoked_by_user', 'updated_at' => now()]);
        $this->audit->record('session_revoked', $s, userId: $userId, type: 'user_sessions');
    }

    public function revokeOthers(int $userId, string $currentFamily): int
    {
        $n = $this->revokeFamilies($userId, 'signed_out_elsewhere', exceptFamily: $currentFamily);
        $this->audit->record('sessions_revoked', User::find($userId), new: ['families' => $n], userId: $userId, type: 'users');

        return $n;
    }

    /** Ends every live sign-in of the user (all, or all but one family). @return int sign-ins ended */
    public function revokeFamilies(int $userId, string $reason, ?string $exceptFamily = null): int
    {
        $q = UserSession::where('user_id', $userId)->whereNull('revoked_at')->when($exceptFamily, fn ($q) => $q->where('family_id', '!=', $exceptFamily));
        $families = (clone $q)->distinct()->count('family_id');
        $q->update(['revoked_at' => now(), 'revoked_reason' => $reason, 'updated_at' => now()]);

        return $families;
    }

    /** UserPreferences (K13); defaults until the user saves. */
    public function preferences(int $userId): array
    {
        $p = DB::table('user_preferences')->where('user_id', $userId)->first();

        return ['theme' => $p->theme ?? 'system', 'density' => $p->density ?? 'default', 'text_size' => $p->text_size ?? 'm',
            'reduce_motion' => (bool) ($p->reduce_motion ?? false), 'sidebar_collapsed' => (bool) ($p->sidebar_collapsed ?? false)];
    }

    public function savePreferences(int $userId, array $in): array
    {
        $row = array_intersect_key($in, array_flip(['theme', 'density', 'text_size', 'reduce_motion', 'sidebar_collapsed']));
        DB::table('user_preferences')->updateOrInsert(['user_id' => $userId], [...$row, 'updated_at' => now()]);
        DB::table('user_preferences')->where('user_id', $userId)->whereNull('created_at')->update(['created_at' => now()]);

        return $this->preferences($userId);
    }

    /** NotificationPreferences (K12) of the user in this company: every event, defaults where not saved. */
    public function notificationPreferences(int $companyId, int $userId): array
    {
        $saved = DB::table('notification_preferences')->where('company_id', $companyId)->where('user_id', $userId)->get()->keyBy('event_key');
        $events = [];
        foreach (self::EVENTS as $key => $d) {
            $r = $saved[$key] ?? null;
            $events[] = ['event_key' => $key, 'in_app' => $r ? (bool) $r->in_app : $d['in_app'], 'email' => $r ? (bool) $r->email : $d['email'], 'sms' => false];
        }

        return ['events' => $events, 'daily_summary_time' => $saved['daily_summary']->send_at ?? '08:00'];
    }

    public function saveNotificationPreferences(int $companyId, int $userId, array $in): array
    {
        DB::transaction(function () use ($companyId, $userId, $in) {
            foreach ($in['events'] as $e) {
                DB::table('notification_preferences')->updateOrInsert(['company_id' => $companyId, 'user_id' => $userId, 'event_key' => $e['event_key']],
                    ['in_app' => (bool) $e['in_app'], 'email' => (bool) $e['email'], 'sms' => false, 'updated_at' => now(), 'created_at' => now()]);
            }
            if (isset($in['daily_summary_time'])) {
                DB::table('notification_preferences')->updateOrInsert(['company_id' => $companyId, 'user_id' => $userId, 'event_key' => 'daily_summary'],
                    ['send_at' => $in['daily_summary_time'], 'updated_at' => now()]);
                DB::table('notification_preferences')->where('company_id', $companyId)->where('user_id', $userId)->where('event_key', 'daily_summary')
                    ->whereNull('created_at')->update(['created_at' => now(), 'in_app' => false]);
            }
        });

        return $this->notificationPreferences($companyId, $userId);
    }
}
