<?php

namespace Tests\Feature\Auth;

use App\Domain\Access\Totp;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** 2-step verification (07 §5, FR-004, TC-AUTH-004): enrolment, the sign-in challenge, recovery codes, company requirement. */
class TwoFactorTest extends TestCase
{
    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->makeUser('owner@meghna.example');
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->owner);
    }

    public function test_enrolment_returns_a_qr_then_the_first_code_confirms_and_shows_recovery_codes_once(): void
    {
        $t = $this->login('owner@meghna.example');
        $start = $this->api('POST', '/api/v1/me/two-factor', $t)->assertOk();
        $uri = $start->json('data.otpauth_uri');
        $this->assertMatchesRegularExpression('#^otpauth://totp/LedgerFlow:owner%40meghna\.example\?secret=[A-Z2-7]{32}&#', $uri);
        $this->assertStringStartsWith('<svg', $start->json('data.qr_svg'));
        $this->assertSame('no-store', explode(',', (string) $start->headers->get('Cache-Control'))[0]);
        $secret = $this->secretFrom($uri);

        // At rest the secret is encrypted, never the base32 text
        $raw = DB::table('users')->where('id', $this->owner->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertStringNotContainsString($secret, (string) $raw);

        $this->assertProblem($this->api('POST', '/api/v1/me/two-factor/confirm', $t, null, ['code' => $this->wrongCode($secret)]), 401, 'two_factor_failed');
        $this->assertProblem($this->api('POST', '/api/v1/me/two-factor/confirm', $t, null, ['code' => '12ab56']), 422, 'validation_failed');
        $this->api('GET', '/api/v1/me', $t)->assertJsonPath('data.two_factor_enabled', false);

        $confirm = $this->api('POST', '/api/v1/me/two-factor/confirm', $t, null, ['code' => $this->code($secret)])->assertCreated();
        $codes = $confirm->json('data.recovery_codes');
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        foreach ($codes as $c) {
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/', $c);
        }
        $stored = (string) DB::table('users')->where('id', $this->owner->id)->value('two_factor_recovery_codes');
        $this->assertStringNotContainsString($codes[0], $stored);
        $this->api('GET', '/api/v1/me', $t)->assertJsonPath('data.two_factor_enabled', true);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->owner->id, 'action' => 'two_factor_enabled']);

        // Already on: a new set-up is refused rather than silently replacing the authenticator
        $this->assertProblem($this->api('POST', '/api/v1/me/two-factor', $t), 409, 'invalid_transition');
        $this->assertProblem($this->api('POST', '/api/v1/me/two-factor/confirm', $t, null, ['code' => $this->code($secret)]), 409, 'invalid_transition');
    }

    public function test_sign_in_with_2fa_needs_a_code_before_any_token_or_cookie(): void
    {
        $secret = $this->enrol('owner@meghna.example');
        $sessionsBefore = DB::table('user_sessions')->count();

        $step1 = $this->postJson('/api/v1/auth/login', ['email' => 'owner@meghna.example', 'password' => self::PASSWORD])->assertOk()
            ->assertJsonPath('data.status', 'two_factor_required')->assertJsonMissingPath('data.access_token');
        $this->assertNull($this->refreshCookie($step1));
        $this->assertSame($sessionsBefore, DB::table('user_sessions')->count(), 'no session before the second step');
        $challenge = $step1->json('data.challenge_token');

        $this->assertProblem($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => $this->wrongCode($secret)]), 401, 'two_factor_failed');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->owner->id, 'action' => 'two_factor_failed']);

        $step2 = $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => $this->code($secret)])->assertOk()
            ->assertJsonPath('data.status', 'authenticated')->assertJsonPath('data.user.two_factor_enabled', true);
        $token = $step2->json('data.access_token');
        $this->assertSame(['pwd', 'otp'], $this->claims($token)['amr']);
        $this->assertNotNull($this->refreshCookie($step2));
        $this->api('GET', '/api/v1/parties?type=customer', $token, $this->company->id)->assertOk();

        // The challenge is single-use
        $this->assertProblem($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $challenge, 'code' => $this->code($secret)]), 401, 'two_factor_failed');

        // Refreshing keeps the second factor in the new token
        $refreshed = $this->withUnencryptedCookie('lf_rt', $this->refreshCookie($step2)->getValue())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->postJson('/api/v1/auth/refresh')->assertOk();
        $this->assertSame(['pwd', 'otp'], $this->claims($refreshed->json('data.access_token'))['amr']);
    }

    public function test_codes_cannot_be_replayed_and_a_challenge_dies_after_five_wrong_codes(): void
    {
        $secret = $this->enrol('owner@meghna.example');
        $c1 = $this->challenge();
        $code = $this->code($secret);
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $c1, 'code' => $code])->assertOk();
        // The same code again, on a new challenge, is refused
        $this->assertProblem($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $this->challenge(), 'code' => $code]), 401, 'two_factor_failed');

        $c2 = $this->challenge();
        for ($i = 0; $i < 5; $i++) {
            $this->assertProblem($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $c2, 'code' => $this->wrongCode($secret)]), 401, 'two_factor_failed');
        }
        $this->assertProblem($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $c2, 'code' => $this->code($secret)]), 401, 'two_factor_failed');
    }

    public function test_too_many_wrong_codes_for_one_user_are_rate_limited(): void
    {
        $secret = $this->enrol('owner@meghna.example');
        $last = null;
        for ($i = 0; $i < 11; $i++) {
            // A new client address each time, so only the per-user 2-step limit can trigger (not the per-IP one)
            $this->withServerVariables(['REMOTE_ADDR' => '10.9.0.'.($i + 10)]);
            $last = $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $this->challenge(), 'code' => $this->wrongCode($secret)]);
        }
        $this->assertProblem($last, 429, 'rate_limited');
        $this->assertNotEmpty($last->headers->get('Retry-After'));
    }

    public function test_a_challenge_expires_after_five_minutes(): void
    {
        $secret = $this->enrol('owner@meghna.example');
        $c = $this->challenge();
        $this->travel(301)->seconds();
        $this->assertProblem($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $c, 'code' => $this->code($secret)]), 401, 'two_factor_failed');
    }

    public function test_each_recovery_code_works_once(): void
    {
        $codes = [];
        $this->enrol('owner@meghna.example', $codes);
        $ok = $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $this->challenge(), 'recovery_code' => strtolower($codes[3])])->assertOk();
        $this->assertSame(['pwd', 'otp'], $this->claims($ok->json('data.access_token'))['amr']);
        $this->assertProblem($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $this->challenge(), 'recovery_code' => $codes[3]]), 401, 'two_factor_failed');
        $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $this->challenge(), 'recovery_code' => str_replace('-', '', $codes[4])])->assertOk();
        $this->assertCount(8, User::find($this->owner->id)->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->owner->id, 'action' => 'two_factor_recovery_code_used']);
    }

    public function test_the_second_step_validates_its_fields(): void
    {
        $this->enrol('owner@meghna.example');
        $c = $this->challenge();
        $this->assertProblem($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $c]), 422, 'validation_failed')
            ->assertJsonPath('errors.code.0', 'Enter the 6-digit code from your authenticator app');
        $this->assertProblem($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $c, 'code' => '123456', 'recovery_code' => 'ABCDE-FGHJK']), 422, 'validation_failed');
        $this->assertProblem($this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $c, 'code' => '123456', 'remember' => true]), 422, 'validation_failed')
            ->assertJsonPath('errors.remember.0', 'This field is not part of the API.');
    }

    public function test_turning_off_needs_the_password(): void
    {
        $this->enrol('owner@meghna.example');
        $t = $this->signInWithCode('owner@meghna.example');
        $this->assertProblem($this->api('DELETE', '/api/v1/me/two-factor', $t, null, ['password' => 'not my password']), 401, 'invalid_credentials');
        $this->assertProblem($this->api('DELETE', '/api/v1/me/two-factor', $t, null, []), 422, 'validation_failed');
        $this->api('DELETE', '/api/v1/me/two-factor', $t, null, ['password' => self::PASSWORD])->assertNoContent();
        $this->assertNull(User::find($this->owner->id)->two_factor_secret);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->owner->id, 'action' => 'two_factor_disabled']);
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@meghna.example', 'password' => self::PASSWORD])->assertJsonPath('data.status', 'authenticated');
    }

    public function test_a_company_that_requires_2fa_refuses_password_only_sign_ins(): void
    {
        $member = $this->makeUser('sales@meghna.example');
        $this->addMember($this->company, $member, 'sales');
        $this->requireTwoFactor();

        // Not enrolled: signs in, /me works (so the app can send them to Security), company calls are refused
        $t = $this->login('sales@meghna.example');
        $this->api('GET', '/api/v1/me', $t, $this->company->id)->assertOk();
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $t, $this->company->id), 403, 'two_factor_required_by_company')
            ->assertJsonPath('detail', 'Turn on 2-step verification in Settings › Security, then continue.');

        // Enrolling in this session counts as the second factor once the token is refreshed
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'sales@meghna.example', 'password' => self::PASSWORD]);
        $t = $login->json('data.access_token');
        $secret = $this->secretFrom($this->api('POST', '/api/v1/me/two-factor', $t)->json('data.otpauth_uri'));
        $this->api('POST', '/api/v1/me/two-factor/confirm', $t, null, ['code' => $this->code($secret)])->assertCreated();
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $t, $this->company->id), 403, 'two_factor_required_by_company');
        $fresh = $this->withUnencryptedCookie('lf_rt', $this->refreshCookie($login)->getValue())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->postJson('/api/v1/auth/refresh')->json('data.access_token');
        $this->api('GET', '/api/v1/parties?type=customer', $fresh, $this->company->id)->assertOk();

        // While the company requires it, 2-step cannot be turned off
        $this->assertProblem($this->api('DELETE', '/api/v1/me/two-factor', $fresh, null, ['password' => self::PASSWORD]), 403, 'two_factor_required_by_company');

        // Another company without the requirement is unaffected by a password-only sign-in
        $other = $this->makeCompany('Padma Foods Ltd.', $this->makeUser('owner@padma.example'));
        $this->addMember($other, $this->owner, 'viewer');
        $owner = $this->login('owner@meghna.example');
        $this->api('GET', '/api/v1/parties?type=customer', $owner, $other->id)->assertOk();
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $owner, $this->company->id), 403, 'two_factor_required_by_company')
            ->assertJsonPath('detail', 'Turn on 2-step verification in Settings › Security, then continue.');
    }

    // ---------------------------------------------------------------------------------------------------------

    /** Turns 2-step on through the API and returns the secret (recovery codes in $codes). */
    private function enrol(string $email, array &$codes = []): string
    {
        $t = $this->login($email);
        $secret = $this->secretFrom($this->api('POST', '/api/v1/me/two-factor', $t)->assertOk()->json('data.otpauth_uri'));
        $codes = $this->api('POST', '/api/v1/me/two-factor/confirm', $t, null, ['code' => $this->code($secret)])->assertCreated()->json('data.recovery_codes');

        return $secret;
    }

    private function challenge(string $email = 'owner@meghna.example'): string
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD])
            ->assertJsonPath('data.status', 'two_factor_required')->json('data.challenge_token');
    }

    private function signInWithCode(string $email): string
    {
        $secret = User::where('email', $email)->first()->two_factor_secret;

        return $this->postJson('/api/v1/auth/two-factor', ['challenge_token' => $this->challenge($email), 'code' => $this->code($secret)])
            ->assertOk()->json('data.access_token');
    }

    /** A code that has not been used yet: the clock moves on one 30-second step, as it would between two sign-ins. */
    private function code(string $secret): string
    {
        $this->travel(Totp::PERIOD)->seconds();

        return Totp::code($secret, Totp::step(now()->getTimestamp()));
    }

    private function wrongCode(string $secret): string
    {
        $valid = array_map(fn ($s) => Totp::code($secret, Totp::step(now()->getTimestamp()) + $s), [-1, 0, 1]);
        for ($n = 0; ; $n++) {
            $c = str_pad((string) $n, 6, '0', STR_PAD_LEFT);
            if (! in_array($c, $valid, true)) {
                return $c;
            }
        }
    }

    private function secretFrom(string $uri): string
    {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $q);

        return $q['secret'];
    }

    private function requireTwoFactor(): void
    {
        CompanySetting::withoutGlobalScopes()->where('company_id', $this->company->id)->update(['require_two_factor' => true]);
    }
}
