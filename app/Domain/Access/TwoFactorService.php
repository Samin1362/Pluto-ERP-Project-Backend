<?php

namespace App\Domain\Access;

use App\Domain\Shared\AuditLogger;
use App\Models\CompanySetting;
use App\Models\User;
use App\Support\Problem\ApiProblem;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 2-step verification (07 §5, FR-004): TOTP enrolment, the sign-in challenge, recovery codes, and the record of
 * which sign-ins were completed with a second factor (`amr` contains `otp`).
 *
 * The secret uses the `encrypted` cast (APP_KEY). Recovery codes are stored as HMAC-SHA256 digests inside the
 * encrypted column, so even a decrypted copy does not reveal them; each works once.
 *
 * Which session families were verified with a code is kept in the cache store, not in user_sessions (the
 * approved schema has no column for it). If that entry is lost, the sign-in counts as password-only: the user
 * is asked to sign in again where a company requires 2-step. It never fails open.
 */
final class TwoFactorService
{
    public const CHALLENGE_SECONDS = 300;

    public const CHALLENGE_ATTEMPTS = 5;

    public const RECOVERY_CODES = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    /** POST /me/two-factor: a new pending secret (replaces an unconfirmed one). */
    public function start(User $user): array
    {
        if ($user->two_factor_confirmed_at !== null) {
            throw new ApiProblem('invalid_transition', '2-step verification is already on. Turn it off first to set up a new authenticator.');
        }
        $secret = Totp::newSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => null])->save();
        $uri = Totp::uri($secret, $user->email, (string) config('ledgerflow.two_factor.issuer'));
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))->writeString($uri);

        return ['otpauth_uri' => $uri, 'qr_svg' => trim(preg_replace('/^<\?xml[^>]*>\s*/', '', $svg))];
    }

    /** POST /me/two-factor/confirm: the first code proves the authenticator works; recovery codes are shown once. */
    public function confirm(User $user, string $code, string $sessionFamily): array
    {
        if ($user->two_factor_confirmed_at !== null || ! $user->two_factor_secret) {
            throw new ApiProblem('invalid_transition', 'Start the set-up first.');
        }
        $this->throttle($user);
        if (! $this->acceptCode($user, $code)) {
            $this->audit->record('two_factor_failed', $user, userId: $user->id, type: 'users');
            throw new ApiProblem('two_factor_failed');
        }
        [$plain, $stored] = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $stored])->save();
        // Entering a valid code counts as a second factor for the session it was entered in.
        $this->markVerified($sessionFamily);
        RateLimiter::clear($this->throttleKey($user));
        $this->audit->record('two_factor_enabled', $user, userId: $user->id, type: 'users');

        return $plain;
    }

    /** DELETE /me/two-factor: needs the password; refused while any company of the user requires 2-step. */
    public function disable(User $user, string $password): void
    {
        if (! Hash::check($password, (string) $user->password_hash)) {
            throw new ApiProblem('invalid_credentials', 'The password is not correct.');
        }
        if ($this->requiredByAnyCompany($user)) {
            throw new ApiProblem('two_factor_required_by_company');
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $this->audit->record('two_factor_disabled', $user, userId: $user->id, type: 'users');
    }

    /** Step 1 of sign-in succeeded for a user with 2-step on: a single-use challenge valid for 5 minutes. */
    public function challenge(User $user, array $client): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Cache::put($this->challengeKey($token), ['user_id' => $user->id, 'attempts' => 0] + $client, self::CHALLENGE_SECONDS);

        return $token;
    }

    /**
     * Step 2: a current code or an unused recovery code. Wrong answers count against the challenge (5) and the
     * user (rate limit); the challenge is consumed on success.
     *
     * @return array{user: User, client: array}
     */
    public function complete(string $token, ?string $code, ?string $recoveryCode): array
    {
        $key = $this->challengeKey($token);
        $challenge = Cache::get($key);
        $user = $challenge ? User::find($challenge['user_id']) : null;
        if (! $user || $user->status !== 'active' || $user->two_factor_confirmed_at === null) {
            Cache::forget($key);
            throw new ApiProblem('two_factor_failed', 'The sign-in has expired. Enter your e-mail and password again.');
        }
        $this->throttle($user);
        $ok = $recoveryCode !== null ? $this->useRecoveryCode($user, $recoveryCode) : $this->acceptCode($user, (string) $code);
        if (! $ok) {
            $challenge['attempts']++;
            $challenge['attempts'] >= self::CHALLENGE_ATTEMPTS ? Cache::forget($key) : Cache::put($key, $challenge, self::CHALLENGE_SECONDS);
            $this->audit->record('two_factor_failed', $user, userId: $user->id, type: 'users');
            throw new ApiProblem('two_factor_failed');
        }
        Cache::forget($key);
        RateLimiter::clear($this->throttleKey($user));

        return ['user' => $user, 'client' => $challenge];
    }

    public function markVerified(string $familyId): void
    {
        Cache::put($this->amrKey($familyId), true, now()->addDays((int) config('ledgerflow.refresh.ttl_days') + 1));
    }

    /** @return list<string> the `amr` claim for tokens of this session family */
    public function amrFor(string $familyId): array
    {
        return Cache::get($this->amrKey($familyId)) === true ? ['pwd', 'otp'] : ['pwd'];
    }

    /** `require_two_factor` in this company's settings (company_settings, K14). */
    public function requiredBy(int $companyId): bool
    {
        return (bool) CompanySetting::withoutGlobalScopes()->where('company_id', $companyId)->value('require_two_factor');
    }

    private function requiredByAnyCompany(User $user): bool
    {
        return DB::table('company_users')->join('company_settings', 'company_settings.company_id', '=', 'company_users.company_id')
            ->where('company_users.user_id', $user->id)->where('company_users.status', 'active')
            ->where('company_settings.require_two_factor', true)->exists();
    }

    /** A code is accepted once: replaying the same step (or an older one) is refused. */
    private function acceptCode(User $user, string $code): bool
    {
        $step = Totp::match((string) $user->two_factor_secret, $code, now()->getTimestamp());
        if ($step === null) {
            return false;
        }
        $lastKey = 'tfa-last-step:'.$user->id;
        $last = Cache::get($lastKey);
        if ($last !== null && $step <= $last) {
            return false;
        }
        Cache::put($lastKey, $step, (Totp::WINDOW * 2 + 2) * Totp::PERIOD);

        return true;
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $digest = $this->digest($code);

        return DB::transaction(function () use ($user, $digest) {
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            $codes = $fresh->two_factor_recovery_codes ?? [];
            $i = null;
            foreach ($codes as $k => $stored) {
                if (hash_equals($stored, $digest)) {
                    $i = $k;
                }
            }
            if ($i === null) {
                return false;
            }
            unset($codes[$i]);
            $fresh->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
            $this->audit->record('two_factor_recovery_code_used', $fresh, new: ['remaining' => count($codes)], userId: $fresh->id, type: 'users');

            return true;
        });
    }

    /** @return array{0: list<string>, 1: list<string>} plain codes (shown once) and their digests (stored) */
    private function newRecoveryCodes(): array
    {
        $plain = [];
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I
        for ($n = 0; $n < self::RECOVERY_CODES; $n++) {
            $c = '';
            for ($i = 0; $i < 10; $i++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plain[] = substr($c, 0, 5).'-'.substr($c, 5);
        }

        return [$plain, array_map(fn ($c) => $this->digest($c), $plain)];
    }

    private function digest(string $code): string
    {
        $normal = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));

        return hash_hmac('sha256', $normal, 'recovery|'.config('app.key'));
    }

    private function throttle(User $user): void
    {
        $key = $this->throttleKey($user);
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw new ApiProblem('rate_limited', headers: ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 900);
    }

    private function throttleKey(User $user): string
    {
        return 'tfa-attempts:'.$user->id;
    }

    private function challengeKey(string $token): string
    {
        return 'tfa-challenge:'.hash('sha256', $token);
    }

    private function amrKey(string $familyId): string
    {
        return 'amr-otp:'.$familyId;
    }
}
