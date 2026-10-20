<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\UserSession;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** 07 §2-4, R-12, R-36, BR-061; TC-AUTH-001/002/003/022. */
class AuthenticationTest extends TestCase
{
    private const CSRF = ['X-Requested-With' => 'XMLHttpRequest', 'Origin' => 'http://localhost'];

    public function test_login_returns_access_token_refresh_cookie_and_user(): void
    {
        $u = $this->makeUser('rafiq@meghna.example');
        $c = $this->makeCompany('Meghna Traders Ltd.', $u);

        $r = $this->postJson('/api/v1/auth/login', ['email' => 'Rafiq@Meghna.example', 'password' => self::PASSWORD, 'device_label' => 'Chrome on Windows']);

        $r->assertOk()->assertJsonPath('data.status', 'authenticated')->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_in', 900)->assertJsonPath('data.user.email', 'rafiq@meghna.example')
            ->assertJsonPath('data.user.companies.0.company_id', $c->id)->assertJsonPath('data.user.companies.0.role', 'Owner');
        $this->assertSame('no-store, private', $r->headers->get('Cache-Control'));
        $cookie = $this->refreshCookie($r);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('strict', $cookie->getSameSite());
        $this->assertSame('/api/v1/auth', $cookie->getPath());
        $session = UserSession::firstOrFail();
        $this->assertSame(hash('sha256', $cookie->getValue()), $session->refresh_token_hash, 'only the hash is stored');
        $this->assertSame('Chrome on Windows', $session->device_label);
        $this->assertTrue(AuditLog::where('action', 'login')->where('user_id', $u->id)->exists());
    }

    public function test_wrong_password_and_unknown_email_get_the_same_generic_401(): void
    {
        $u = $this->makeUser('rafiq@meghna.example');
        $a = $this->assertProblem($this->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => 'wrong password!']), 401, 'invalid_credentials');
        $b = $this->assertProblem($this->postJson('/api/v1/auth/login', ['email' => 'nobody@meghna.example', 'password' => 'wrong password!']), 401, 'invalid_credentials');
        $this->assertSame($a->json('title'), $b->json('title'));
        $this->assertSame(1, $u->fresh()->failed_login_count);
        $this->assertTrue(AuditLog::where('action', 'login_failed')->exists());
        $this->assertSame(0, UserSession::count());
    }

    public function test_sixth_failure_from_one_source_is_throttled_but_other_sources_still_sign_in(): void
    {
        $this->makeUser('rafiq@meghna.example');
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
                ->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => 'wrong password!'])->assertStatus(401);
        }
        $r = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD]);
        $this->assertProblem($r, 429, 'rate_limited');
        $this->assertGreaterThan(0, (int) $r->headers->get('Retry-After'));

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_account_locks_after_50_failures_and_answers_401_not_423(): void
    {
        $u = $this->makeUser('rafiq@meghna.example');
        config(['ledgerflow.login.max_failures_per_account' => 3]); // same rule, smaller number to keep the test fast
        foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'wrong password!']);
        }
        $this->assertTrue($u->fresh()->locked_until->isFuture());
        $r = $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.4'])->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => self::PASSWORD]);
        $this->assertProblem($r, 401, 'invalid_credentials');
    }

    public function test_disabled_user_cannot_sign_in(): void
    {
        $this->makeUser('gone@meghna.example', 'disabled');
        $this->assertProblem($this->postJson('/api/v1/auth/login', ['email' => 'gone@meghna.example', 'password' => self::PASSWORD]), 401, 'invalid_credentials');
    }

    public function test_login_validation_unknown_fields_and_malformed_json(): void
    {
        $r = $this->assertProblem($this->postJson('/api/v1/auth/login', ['password' => 'x']), 422, 'validation_failed');
        $r->assertJsonPath('errors.email.0', 'Enter your e-mail address');
        $this->assertProblem($this->postJson('/api/v1/auth/login', ['email' => 'a@b.example', 'password' => 'xxxxxxxxxx', 'remember' => true]), 422, 'validation_failed')
            ->assertJsonPath('errors.remember.0', 'This field is not part of the API.');
        $r = $this->call('POST', '/api/v1/auth/login', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"email": ');
        $this->assertProblem($r, 400, 'malformed_json');
    }

    public function test_refresh_rotates_the_cookie_and_reuse_revokes_the_whole_family(): void
    {
        $this->makeUser('rafiq@meghna.example');
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD]);
        $first = $this->refreshCookie($login)->getValue();

        $r = $this->withUnencryptedCookie('lf_rt', $first)->withHeaders(self::CSRF)->postJson('/api/v1/auth/refresh');
        $r->assertOk()->assertJsonStructure(['data' => ['access_token', 'token_type', 'expires_in']]);
        $second = $this->refreshCookie($r)->getValue();
        $this->assertNotSame($first, $second);
        $newAccess = $r->json('data.access_token');
        $this->api('GET', '/api/v1/me', $newAccess)->assertOk();

        // The old token is presented again: theft is assumed, every session of this sign-in is revoked.
        $this->assertProblem($this->withUnencryptedCookie('lf_rt', $first)->withHeaders(self::CSRF)->postJson('/api/v1/auth/refresh'), 401, 'refresh_reuse_detected');
        $this->assertSame(0, UserSession::whereNull('revoked_at')->count());
        $this->assertProblem($this->withUnencryptedCookie('lf_rt', $second)->withHeaders(self::CSRF)->postJson('/api/v1/auth/refresh'), 401, 'refresh_token_invalid');
        $this->assertProblem($this->api('GET', '/api/v1/me', $newAccess), 401, 'token_invalid');
    }

    public function test_refresh_requires_the_csrf_header(): void
    {
        $this->makeUser('rafiq@meghna.example');
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD]);
        $rt = $this->refreshCookie($login)->getValue();
        $this->assertProblem($this->withUnencryptedCookie('lf_rt', $rt)->postJson('/api/v1/auth/refresh'), 401, 'refresh_token_invalid');
        $this->assertProblem($this->withUnencryptedCookie('lf_rt', $rt)->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Origin' => 'https://evil.example'])
            ->postJson('/api/v1/auth/refresh'), 401, 'refresh_token_invalid');
    }

    public function test_logout_works_with_an_expired_access_token_and_kills_the_session(): void
    {
        $this->makeUser('rafiq@meghna.example');
        config(['ledgerflow.jwt.ttl_seconds' => -120]); // tokens issued already expired
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD]);
        $expired = $login->json('data.access_token');
        $rt = $this->refreshCookie($login)->getValue();
        $this->assertProblem($this->api('GET', '/api/v1/me', $expired), 401, 'token_expired');

        $out = $this->withUnencryptedCookie('lf_rt', $rt)->withHeaders(self::CSRF + ['Authorization' => 'Bearer '.$expired])->postJson('/api/v1/auth/logout');
        $out->assertNoContent();
        $cleared = $this->refreshCookie($out);
        $this->assertTrue($cleared->isCleared());
        $this->assertNotNull(UserSession::first()->revoked_at);
        $this->assertTrue(AuditLog::where('action', 'logout')->exists());
        $this->assertProblem($this->withUnencryptedCookie('lf_rt', $rt)->withHeaders(self::CSRF)->postJson('/api/v1/auth/refresh'), 401, 'refresh_token_invalid');
    }

    public function test_logout_revokes_access_tokens_of_the_sign_in(): void
    {
        $this->makeUser('rafiq@meghna.example');
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD]);
        $access = $login->json('data.access_token');
        $rt = $this->refreshCookie($login)->getValue();
        $this->api('GET', '/api/v1/me', $access)->assertOk();
        $this->withUnencryptedCookie('lf_rt', $rt)->withHeaders(self::CSRF)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertProblem($this->api('GET', '/api/v1/me', $access), 401, 'token_invalid');
    }

    public function test_tokens_are_rejected_when_missing_forged_or_after_a_password_change(): void
    {
        $u = $this->makeUser('rafiq@meghna.example');
        $this->assertProblem($this->getJson('/api/v1/me'), 401, 'unauthenticated');
        $this->assertProblem($this->api('GET', '/api/v1/me', 'not.a.jwt'), 401, 'token_invalid');
        $token = $this->login('rafiq@meghna.example');
        [$h, $p, $s] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
        $claims['sub'] = '999';
        $forged = $h.'.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'.$s;
        $this->assertProblem($this->api('GET', '/api/v1/me', $forged), 401, 'token_invalid');

        $u->forceFill(['password_hash' => Hash::make('a brand new password'), 'password_changed_at' => now()])->save();
        $this->assertProblem($this->api('GET', '/api/v1/me', $token), 401, 'token_invalid'); // BR-061 via pwv claim
    }

    public function test_request_id_is_echoed_and_used_in_problems(): void
    {
        $id = '4f0c7a62-8a8e-4c1f-9b55-0d2f3c1a9e10';
        $r = $this->withHeaders(['X-Request-Id' => $id])->getJson('/api/v1/me');
        $this->assertSame($id, $r->headers->get('X-Request-Id'));
        $r->assertJsonPath('request_id', $id);
    }

    public function test_auth_endpoints_share_a_per_ip_limit(): void
    {
        config(['ledgerflow.rate_limits.auth_per_ip' => 3]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.7.7.7']);
        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->postJson('/api/v1/auth/refresh')->assertStatus(401);
        }
        $r = $this->assertProblem($this->postJson('/api/v1/auth/login', ['email' => 'nobody@meghna.example', 'password' => 'x']), 429, 'rate_limited');
        $this->assertNotEmpty($r->headers->get('Retry-After'));
        // Another address has its own budget
        $this->withServerVariables(['REMOTE_ADDR' => '10.7.7.8'])->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->postJson('/api/v1/auth/refresh')->assertStatus(401);
    }

    public function test_authenticated_calls_are_limited_per_user(): void
    {
        $this->makeUser('owner@meghna.example');
        $this->makeUser('sales@meghna.example');
        $owner = $this->login('owner@meghna.example');
        $sales = $this->login('sales@meghna.example');
        config(['ledgerflow.rate_limits.api_per_user' => 3]);
        for ($i = 0; $i < 3; $i++) {
            $this->api('GET', '/api/v1/me', $owner)->assertOk();
        }
        $r = $this->assertProblem($this->api('GET', '/api/v1/me', $owner), 429, 'rate_limited');
        $this->assertNotEmpty($r->headers->get('Retry-After'));
        $this->api('GET', '/api/v1/me', $sales)->assertOk(); // each user has their own budget
    }
}
