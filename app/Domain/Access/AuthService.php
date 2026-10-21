<?php

namespace App\Domain\Access;

use App\Domain\Shared\AuditLogger;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Sign-in, refresh rotation and sign-out (07 §2-3, R-12, R-36).
 * Refresh tokens are opaque 256-bit values stored only as SHA-256 hashes in user_sessions.
 */
final class AuthService
{
    public function __construct(private readonly JwtService $jwt, private readonly AuditLogger $audit, private readonly TwoFactorService $twoFactor) {}

    /**
     * Step 1. With 2-step on, no session is created yet: the caller gets a challenge for POST /auth/two-factor.
     *
     * @return array{tokens: array, refresh: string, user: User}|array{challenge_token: string}
     */
    public function login(string $email, string $password, string $ip, ?string $userAgent, ?string $deviceLabel): array
    {
        $email = Str::lower(trim($email));
        $sourceKey = 'login-src:'.hash('sha256', $email.'|'.$ip);
        $accountKey = 'login-acct:'.hash('sha256', $email);
        $max = (int) config('pluto.login.max_failures_per_source');
        if (RateLimiter::tooManyAttempts($sourceKey, $max)) {
            throw new ApiProblem('rate_limited', headers: ['Retry-After' => (string) RateLimiter::availableIn($sourceKey)]);
        }

        $user = User::where('email', $email)->first();
        // Always run a hash check so response time does not reveal whether the e-mail exists.
        $valid = Hash::check($password, $user?->password_hash ?? $this->dummyHash());
        $locked = $user?->locked_until !== null && $user->locked_until->isFuture();
        if (! $user || ! $valid || $locked || $user->status !== 'active') {
            RateLimiter::hit($sourceKey, (int) config('pluto.login.source_decay_seconds'));
            RateLimiter::hit($accountKey, (int) config('pluto.login.account_lock_seconds'));
            if ($user) {
                $user->failed_login_count = $user->failed_login_count + 1;
                if (RateLimiter::attempts($accountKey) >= (int) config('pluto.login.max_failures_per_account')) {
                    $user->locked_until = now()->addSeconds((int) config('pluto.login.account_lock_seconds'));
                }
                $user->save();
            }
            $this->audit->record('login_failed', $user, userId: $user?->id, type: 'users');
            // Same answer for wrong password, unknown e-mail, locked or inactive account (no enumeration, R-12).
            throw new ApiProblem('invalid_credentials');
        }

        RateLimiter::clear($sourceKey);
        if (Hash::needsRehash($user->password_hash)) {
            $user->password_hash = Hash::make($password);
        }
        $user->failed_login_count = 0;
        $user->save();

        if ($user->two_factor_confirmed_at !== null) {
            return ['challenge_token' => $this->twoFactor->challenge($user, ['ip' => $ip, 'user_agent' => $userAgent, 'device_label' => $deviceLabel])];
        }

        return $this->signIn($user, ['pwd'], $ip, $userAgent, $deviceLabel);
    }

    /**
     * Step 2 (POST /auth/two-factor): a TOTP or recovery code for the challenge from step 1.
     *
     * @return array{tokens: array, refresh: string, user: User}
     */
    public function completeTwoFactor(string $challengeToken, ?string $code, ?string $recoveryCode, string $ip, ?string $userAgent): array
    {
        ['user' => $user, 'client' => $client] = $this->twoFactor->complete($challengeToken, $code, $recoveryCode);

        return $this->signIn($user, ['pwd', 'otp'], $ip, $userAgent ?? $client['user_agent'] ?? null, $client['device_label'] ?? null);
    }

    /** @param list<string> $amr */
    private function signIn(User $user, array $amr, string $ip, ?string $userAgent, ?string $deviceLabel): array
    {
        $user->last_login_at = now();
        $user->save();
        $family = (string) Str::uuid();
        if (in_array('otp', $amr, true)) {
            $this->twoFactor->markVerified($family);
        }
        [$session, $refresh] = $this->newSession($user, $family, $ip, $userAgent, $deviceLabel);
        $this->audit->record('login', $user, new: ['amr' => $amr], userId: $user->id, type: 'users');

        return ['tokens' => $this->jwt->issue($user, $session->id, $amr), 'refresh' => $refresh, 'user' => $user];
    }

    /**
     * Rotates the refresh token. Presenting a token that was already rotated means it was copied:
     * the whole family is revoked and the user must sign in again (07 §3.2).
     *
     * @return array{tokens: array, refresh: string}
     */
    public function refresh(?string $raw, string $ip, ?string $userAgent): array
    {
        if (! $raw) {
            throw new ApiProblem('refresh_token_invalid');
        }
        $hash = hash('sha256', $raw);

        $outcome = DB::transaction(function () use ($hash, $ip, $userAgent) {
            $session = UserSession::where('refresh_token_hash', $hash)->lockForUpdate()->first();
            if (! $session) {
                return ['error' => 'refresh_token_invalid'];
            }
            if ($session->replaced_by_id !== null) {
                UserSession::where('family_id', $session->family_id)->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'revoked_reason' => 'reuse_detected', 'updated_at' => now()]);
                $this->audit->record('refresh_reuse_detected', $session, userId: $session->user_id, type: 'user_sessions');

                return ['error' => 'refresh_reuse_detected'];
            }
            if ($session->revoked_at !== null || $session->expires_at->isPast()) {
                return ['error' => 'refresh_token_invalid'];
            }
            $user = User::find($session->user_id);
            if (! $user || $user->status !== 'active') {
                return ['error' => 'refresh_token_invalid'];
            }
            [$next, $refresh] = $this->newSession($user, $session->family_id, $ip, $userAgent, $session->device_label);
            // The old row is linked, not revoked: access tokens issued for it stay valid until they expire (<= 15 min),
            // while presenting its refresh token again is detected as reuse above.
            $session->forceFill(['replaced_by_id' => $next->id])->save();

            return ['tokens' => $this->jwt->issue($user, $next->id, $this->twoFactor->amrFor($session->family_id)), 'refresh' => $refresh];
        });
        if (isset($outcome['error'])) {
            throw new ApiProblem($outcome['error']);
        }

        return $outcome;
    }

    /** Revokes the session of this refresh token. Always succeeds from the client's point of view (R-36). */
    public function logout(?string $raw): void
    {
        if (! $raw) {
            return;
        }
        $session = UserSession::where('refresh_token_hash', hash('sha256', $raw))->whereNull('revoked_at')->first();
        if ($session) {
            // Revoke the whole rotation family so every access token of this sign-in stops working at once.
            UserSession::where('family_id', $session->family_id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'logout', 'updated_at' => now()]);
            $this->audit->record('logout', $session, userId: $session->user_id, type: 'user_sessions');
        }
    }

    /** A real hash of a random value with the current cost settings, so unknown e-mails cost as much as known ones. */
    private function dummyHash(): string
    {
        return Cache::rememberForever('auth:dummy-hash:'.config('hashing.driver'), fn () => Hash::make(Str::random(40)));
    }

    /** @return array{0: UserSession, 1: string} */
    private function newSession(User $user, string $familyId, string $ip, ?string $userAgent, ?string $deviceLabel): array
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = UserSession::create([
            'user_id' => $user->id,
            'refresh_token_hash' => hash('sha256', $raw),
            'family_id' => $familyId,
            'device_label' => $deviceLabel ? substr($deviceLabel, 0, 120) : null,
            'user_agent' => $userAgent ? substr($userAgent, 0, 255) : null,
            'ip_address' => $ip,
            'last_seen_at' => now(),
            'expires_at' => now()->addDays((int) config('pluto.refresh.ttl_days')),
        ]);

        return [$session, $raw];
    }
}
