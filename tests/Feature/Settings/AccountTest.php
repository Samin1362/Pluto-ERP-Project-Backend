<?php

namespace Tests\Feature\Settings;

use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Own account: PUT /me/password, /me/sessions, /me/preferences, /me/notification-preferences, POST /auth/password/forgot
 * and /reset (K12–K14, S2; NFR-011; BR-061; TC-AUTH-012, TC-AUTH-016; backlog P15.1-BE-07/08).
 */
class AccountTest extends TestCase
{
    use BuildsMasterData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
    }

    public function test_t_c_auth_012_changing_the_password_ends_every_other_sign_in(): void
    {
        $here = $this->login('owner@meghna.example');
        $phone = $this->login('owner@meghna.example', '10.0.0.2');
        $sessions = $this->api('GET', '/api/v1/me/sessions', $here)->assertOk();
        $this->assertCount(2, $sessions->json('data'));
        $this->assertSame(1, count(array_filter(array_column($sessions->json('data'), 'current'))), 'this sign-in is marked current');

        $this->assertProblem($this->api('PUT', '/api/v1/me/password', $here, null, ['current_password' => 'wrong-password', 'password' => 'Bright-River-2026',
            'password_confirmation' => 'Bright-River-2026']), 422, 'validation_failed')->assertJsonPath('errors.current_password.0', 'Your current password is not right');
        $this->assertProblem($this->api('PUT', '/api/v1/me/password', $here, null, ['current_password' => self::PASSWORD, 'password' => 'short',
            'password_confirmation' => 'short']), 422, 'validation_failed')->assertJsonPath('errors.password.0', 'Use at least 10 characters');
        $this->api('PUT', '/api/v1/me/password', $here, null, ['current_password' => self::PASSWORD, 'password' => 'Bright-River-2026',
            'password_confirmation' => 'Bright-River-2026'])->assertNoContent();

        $this->assertProblem($this->api('GET', '/api/v1/me', $phone), 401, 'token_invalid');
        $this->assertSame(1, DB::table('user_sessions')->whereNull('revoked_at')->whereNull('replaced_by_id')->count(), 'only this sign-in is left');
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@meghna.example', 'password' => 'Bright-River-2026'])->assertOk();
        $this->assertTrue(DB::table('audit_logs')->where('action', 'password_changed')->exists());
    }

    public function test_sessions_can_be_signed_out_one_by_one_or_all_others(): void
    {
        $here = $this->login('owner@meghna.example');
        $tablet = $this->login('owner@meghna.example', '10.0.0.2');
        $laptop = $this->login('owner@meghna.example', '10.0.0.3');
        $other = collect($this->api('GET', '/api/v1/me/sessions', $here)->json('data'))->where('current', false)->where('ip_address', '10.0.0.2')->first();
        $this->api('DELETE', "/api/v1/me/sessions/{$other['id']}", $here)->assertNoContent();
        $this->assertProblem($this->api('GET', '/api/v1/me', $tablet), 401, 'token_invalid');
        $this->api('GET', '/api/v1/me', $laptop)->assertOk();
        $this->assertProblem($this->api('DELETE', '/api/v1/me/sessions/999999', $here), 404, 'not_found');

        $this->api('POST', '/api/v1/me/sessions/revoke-others', $here)->assertNoContent();
        $this->assertProblem($this->api('GET', '/api/v1/me', $laptop), 401, 'token_invalid');
        $this->api('GET', '/api/v1/me', $here)->assertOk();
        $this->assertCount(1, $this->api('GET', '/api/v1/me/sessions', $here)->json('data'));
    }

    public function test_appearance_and_notification_preferences(): void
    {
        $t = $this->login('owner@meghna.example');
        $this->api('GET', '/api/v1/me/preferences', $t)->assertOk()->assertJsonPath('data.theme', 'system')->assertJsonPath('data.density', 'default');
        $this->api('PUT', '/api/v1/me/preferences', $t, null, ['theme' => 'dark', 'density' => 'compact'])->assertOk()
            ->assertJsonPath('data.theme', 'dark')->assertJsonPath('data.density', 'compact')->assertJsonPath('data.text_size', 'm');
        $this->api('GET', '/api/v1/me/preferences', $t)->assertJsonPath('data.theme', 'dark');
        $this->assertProblem($this->api('PUT', '/api/v1/me/preferences', $t, null, ['theme' => 'neon']), 422, 'validation_failed');

        $c = $this->books->id;
        $n = $this->api('GET', '/api/v1/me/notification-preferences', $t, $c)->assertOk();
        $this->assertSame(['approval_requested', 'approval_approved', 'approval_rejected', 'invoice_overdue', 'bill_due_soon', 'daily_summary'], array_column($n->json('data.events'), 'event_key'));
        $n->assertJsonPath('data.daily_summary_time', '08:00')->assertJsonPath('data.events.5.email', false);
        $this->api('PUT', '/api/v1/me/notification-preferences', $t, $c, ['events' => [['event_key' => 'invoice_overdue', 'in_app' => false, 'email' => true],
            ['event_key' => 'daily_summary', 'in_app' => false, 'email' => true]], 'daily_summary_time' => '07:30'])->assertOk()
            ->assertJsonPath('data.events.3.in_app', false)->assertJsonPath('data.events.3.email', true)->assertJsonPath('data.daily_summary_time', '07:30');
        $this->assertSame('07:30', DB::table('notification_preferences')->where('event_key', 'daily_summary')->value('send_at'));
        $this->assertProblem($this->api('PUT', '/api/v1/me/notification-preferences', $t, $c, ['events' => [['event_key' => 'party_time', 'in_app' => true, 'email' => true]]]), 422, 'validation_failed');
        $this->assertProblem($this->api('PUT', '/api/v1/me/notification-preferences', $t, $c, ['events' => [], 'daily_summary_time' => '06:00']), 422, 'validation_failed');
        $this->assertProblem($this->api('PUT', '/api/v1/me/notification-preferences', $t, $c, ['events' => [['event_key' => 'bill_due_soon', 'in_app' => true, 'email' => false, 'sms' => true]]]), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/me/notification-preferences', $t), 400, 'company_required');
    }

    public function test_t_c_auth_016_a_reset_link_works_once_and_ends_every_session(): void
    {
        Mail::fake();
        config(['pluto.rate_limits.auth_per_ip_email' => 50]); // eight credential calls for one e-mail in a second; the limit is tested in AuthenticationTest
        $old = $this->login('owner@meghna.example');
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@meghna.example'])->assertStatus(202);
        Mail::assertNothingQueued();
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'Owner@Meghna.example'])->assertStatus(202);
        $link = null;
        Mail::assertQueued(PasswordResetMail::class, function (PasswordResetMail $m) use (&$link) {
            $link = $m->link;

            return $m->hasTo('owner@meghna.example') && $m->minutes === 60;
        });
        parse_str((string) parse_url($link, PHP_URL_QUERY), $q);
        $this->assertStringContainsString('/reset-password?', $link);

        $this->assertProblem($this->postJson('/api/v1/auth/password/reset', ['token' => 'not-the-token', 'email' => 'owner@meghna.example', 'password' => 'Calm-Harbour-77',
            'password_confirmation' => 'Calm-Harbour-77']), 422, 'validation_failed')->assertJsonStructure(['errors' => ['token']]);
        $this->assertProblem($this->postJson('/api/v1/auth/password/reset', ['token' => $q['token'], 'email' => 'owner@meghna.example', 'password' => 'Calm-Harbour-77',
            'password_confirmation' => 'Calm-Harbour-78']), 422, 'validation_failed');
        $this->postJson('/api/v1/auth/password/reset', ['token' => $q['token'], 'email' => 'owner@meghna.example', 'password' => 'Calm-Harbour-77',
            'password_confirmation' => 'Calm-Harbour-77'])->assertNoContent();
        $this->assertProblem($this->api('GET', '/api/v1/me', $old), 401, 'token_invalid');
        $this->assertProblem($this->postJson('/api/v1/auth/password/reset', ['token' => $q['token'], 'email' => 'owner@meghna.example', 'password' => 'Calm-Harbour-99',
            'password_confirmation' => 'Calm-Harbour-99']), 422, 'validation_failed');
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@meghna.example', 'password' => 'Calm-Harbour-77'])->assertOk();

        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'owner@meghna.example'])->assertStatus(202);
        $this->travel(61)->minutes();
        Mail::assertQueued(PasswordResetMail::class, 2);
    }
}
