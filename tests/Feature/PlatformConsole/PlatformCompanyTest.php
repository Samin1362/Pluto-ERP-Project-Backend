<?php

namespace Tests\Feature\PlatformConsole;

use App\Domain\Access\JwtService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * /platform/companies (K17 platform console; PEP-483): only e-mails in PLUTO_PLATFORM_ADMINS on a 2-step sign-in may
 * list, create and suspend companies; a company made here gets an Owner invitation and the admin never joins it.
 */
class PlatformCompanyTest extends TestCase
{
    use BuildsMasterData;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany(); // Meghna Traders Ltd., owner@meghna.example
        $this->makeUser('admin@pluto.example');
        config(['pluto.platform_admins' => ['admin@pluto.example']]);
        $this->admin = $this->withOtp($this->login('admin@pluto.example'), 'admin@pluto.example');
    }

    /** The same session's token with the second factor (the real flow is /auth/two-factor, covered in TwoFactorTest). */
    private function withOtp(string $token, string $email): string
    {
        return app(JwtService::class)->issue(User::where('email', $email)->firstOrFail(), (int) $this->claims($token)['sid'], ['pwd', 'otp'])['access_token'];
    }

    private function send(string $uri, array $body, ?string $token = null)
    {
        return $this->api('POST', $uri, $token ?? $this->admin, null, $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_a_platform_admin_creates_a_company_for_a_customer_without_joining_it(): void
    {
        $r = $this->send('/api/v1/platform/companies', ['legal_name' => 'Padma Agro Ltd.', 'business_type' => 'partnership',
            'owner_email' => 'Karim@Padma.example', 'owner_name' => 'Karim Uddin'])->assertCreated();
        $r->assertJsonPath('data.legal_name', 'Padma Agro Ltd.')->assertJsonPath('data.status', 'active')->assertJsonPath('data.owner_email', 'karim@padma.example')
            ->assertJsonPath('data.owner_name', 'Karim Uddin')->assertJsonPath('data.owner_pending', true)->assertJsonPath('data.members', 0);
        $id = $r->json('data.id');

        $this->assertSame(0, DB::table('company_users')->where('company_id', $id)->count(), 'the admin is not a member');
        $invite = DB::table('user_invitations')->where('company_id', $id)->first();
        $this->assertSame('karim@padma.example', $invite->email);
        $this->assertSame('owner', DB::table('roles')->where('id', $invite->role_id)->value('slug'));
        $this->assertTrue(DB::table('ledgers')->where('company_id', $id)->exists(), 'chart of accounts seeded');
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $id)->where('action', 'created')->exists());
        $this->assertProblem($this->api('GET', '/api/v1/company', $this->admin, $id), 403, 'not_a_member');
    }

    public function test_the_list_shows_every_company_with_its_owner_members_and_activity(): void
    {
        $this->send('/api/v1/platform/companies', ['legal_name' => 'Padma Agro Ltd.', 'business_type' => 'partnership', 'owner_email' => 'karim@padma.example']);
        $r = $this->api('GET', '/api/v1/platform/companies', $this->admin)->assertOk();
        $r->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.legal_name', 'Padma Agro Ltd.')->assertJsonPath('data.1.legal_name', 'Meghna Traders Ltd.')
            ->assertJsonPath('data.1.owner_email', 'owner@meghna.example')->assertJsonPath('data.1.owner_pending', false)->assertJsonPath('data.1.members', 1);
        $this->assertNotNull($r->json('data.0.last_activity_at'));

        $this->api('GET', '/api/v1/platform/companies?q=meghna.example', $this->admin)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.legal_name', 'Meghna Traders Ltd.');
        $this->api('GET', '/api/v1/platform/companies?status=suspended', $this->admin)->assertJsonPath('meta.total', 0);
        $this->assertProblem($this->api('GET', '/api/v1/platform/companies?colour=red', $this->admin), 422, 'validation_failed');
    }

    public function test_a_suspended_company_cannot_be_opened_until_it_is_reactivated(): void
    {
        $owner = $this->login('owner@meghna.example');
        $this->api('GET', '/api/v1/company', $owner, $this->books->id)->assertOk();

        $this->send("/api/v1/platform/companies/{$this->books->id}/suspend", ['reason' => 'Subscription unpaid for November'])->assertOk()->assertJsonPath('data.status', 'suspended');
        $this->assertProblem($this->api('GET', '/api/v1/company', $owner, $this->books->id), 403, 'not_a_member');
        $this->assertProblem($this->send("/api/v1/platform/companies/{$this->books->id}/suspend", ['reason' => 'again']), 409, 'invalid_transition');
        $this->assertProblem($this->send("/api/v1/platform/companies/{$this->books->id}/reactivate", []), 422, 'validation_failed');

        $this->send("/api/v1/platform/companies/{$this->books->id}/reactivate", ['reason' => 'Paid'])->assertOk()->assertJsonPath('data.status', 'active');
        $this->api('GET', '/api/v1/company', $owner, $this->books->id)->assertOk();
        $log = DB::table('audit_logs')->where('company_id', $this->books->id)->whereIn('action', ['suspended', 'reactivated'])->orderBy('id')->pluck('action')->all();
        $this->assertSame(['suspended', 'reactivated'], $log);
        $this->assertProblem($this->send('/api/v1/platform/companies/999999/suspend', ['reason' => 'x']), 404, 'not_found');
    }

    public function test_only_platform_admins_on_a_two_step_sign_in(): void
    {
        $owner = $this->withOtp($this->login('owner@meghna.example'), 'owner@meghna.example'); // a company Owner is not a platform admin
        $this->assertProblem($this->api('GET', '/api/v1/platform/companies', $owner), 403, 'platform_admin_only');
        $this->assertProblem($this->send('/api/v1/platform/companies', ['legal_name' => 'X', 'business_type' => 'partnership', 'owner_email' => 'x@x.example'], $owner), 403, 'platform_admin_only');
        $this->assertProblem($this->send("/api/v1/platform/companies/{$this->books->id}/suspend", ['reason' => 'x'], $owner), 403, 'platform_admin_only');

        $noOtp = $this->login('admin@pluto.example');
        $this->assertProblem($this->api('GET', '/api/v1/platform/companies', $noOtp), 403, 'platform_two_factor_required');
        $this->assertProblem($this->api('GET', '/api/v1/platform/companies', null), 401, 'unauthenticated');

        config(['pluto.platform_admins' => []]);
        $this->assertProblem($this->api('GET', '/api/v1/platform/companies', $this->admin), 403, 'platform_admin_only');
    }

    public function test_create_validation(): void
    {
        $r = $this->send('/api/v1/platform/companies', ['legal_name' => '', 'business_type' => 'club', 'owner_email' => 'not-an-email', 'colour' => 'red']);
        $this->assertProblem($r, 422, 'validation_failed');
    }
}
