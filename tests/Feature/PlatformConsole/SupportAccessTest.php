<?php

namespace Tests\Feature\PlatformConsole;

use App\Domain\Access\JwtService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Read-only support access (K17; PEP-485): a platform admin opens a customer's company for a limited time with a
 * reason; the owners are told, the audit log keeps it, every read works and every change is refused.
 */
class SupportAccessTest extends TestCase
{
    use BuildsMasterData;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany(); // Meghna Traders Ltd., owner@meghna.example
        $this->customer();
        $this->makeUser('admin@pluto.example');
        config(['pluto.platform_admins' => ['admin@pluto.example']]);
        $this->admin = $this->otp($this->login('admin@pluto.example'), 'admin@pluto.example');
    }

    private function otp(string $token, string $email): string
    {
        return app(JwtService::class)->issue(User::where('email', $email)->firstOrFail(), (int) $this->claims($token)['sid'], ['pwd', 'otp'])['access_token'];
    }

    private function open(array $body = ['reason' => 'Customer asked why the trial balance does not tally', 'minutes' => 30])
    {
        return $this->api('POST', "/api/v1/platform/companies/{$this->books->id}/support-access", $this->admin, null, $body);
    }

    public function test_an_admin_reads_a_company_for_a_limited_time_and_its_owners_are_told(): void
    {
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $this->admin, $this->books->id), 403, 'not_a_member');

        $r = $this->open()->assertCreated();
        $r->assertJsonPath('data.company_id', $this->books->id)->assertJsonPath('data.reason', 'Customer asked why the trial balance does not tally');
        $this->assertNotNull($r->json('data.expires_at'));

        $this->api('GET', '/api/v1/parties?type=customer', $this->admin, $this->books->id)->assertOk()->assertJsonPath('meta.total', 1);
        $this->api('GET', '/api/v1/reports/trial-balance', $this->admin, $this->books->id)->assertOk();
        $me = $this->api('GET', '/api/v1/me', $this->admin, $this->books->id)->assertOk();
        $me->assertJsonPath('data.support_access.company_id', $this->books->id)->assertJsonPath('data.active_company_id', $this->books->id);
        $this->assertNotEmpty($me->json('data.permissions'));

        $owner = User::where('email', 'owner@meghna.example')->value('id');
        $note = DB::table('notifications')->where('company_id', $this->books->id)->where('user_id', $owner)->where('event_key', 'support_access')->first();
        $this->assertNotNull($note, 'the owner is told');
        $this->assertStringContainsString('trial balance', $note->body);
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'support_access_started')->exists());
        $this->assertSame(0, DB::table('company_users')->where('company_id', $this->books->id)->whereIn('user_id', User::where('email', 'admin@pluto.example')->pluck('id'))->count(),
            'the admin never becomes a member');
        $this->api('GET', '/api/v1/me', $this->login('owner@meghna.example'))->assertOk()->assertJsonPath('data.support_access', null);
    }

    public function test_nothing_can_be_changed_during_a_support_visit(): void
    {
        $this->open()->assertCreated();
        $h = ['Idempotency-Key' => (string) Str::uuid()];
        $this->assertProblem($this->api('POST', '/api/v1/parties', $this->admin, $this->books->id, ['party_type' => 'customer', 'name' => 'X', 'mobile' => '01711999888'], $h), 403, 'support_read_only');
        $id = DB::table('parties')->where('company_id', $this->books->id)->value('id');
        $this->assertProblem($this->api('PATCH', "/api/v1/parties/{$id}", $this->admin, $this->books->id, ['name' => 'Changed'], ['If-Match' => 'W/"1"']), 403, 'support_read_only');
        $this->assertProblem($this->api('DELETE', "/api/v1/parties/{$id}", $this->admin, $this->books->id), 403, 'support_read_only');
        $this->assertSame(1, DB::table('parties')->where('company_id', $this->books->id)->count());
    }

    public function test_access_ends_at_its_time_or_when_the_admin_ends_it(): void
    {
        $this->open()->assertCreated();
        $this->api('DELETE', "/api/v1/platform/companies/{$this->books->id}/support-access", $this->admin)->assertNoContent();
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $this->admin, $this->books->id), 403, 'not_a_member');
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'support_access_ended')->exists());

        $this->open(['reason' => 'Second look'])->assertCreated();
        $this->travel(61)->minutes(); // default 60 minutes
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $this->otp($this->login('admin@pluto.example'), 'admin@pluto.example'), $this->books->id), 403, 'not_a_member');
    }

    public function test_only_a_platform_admin_on_a_two_step_sign_in(): void
    {
        $this->open()->assertCreated();
        $noOtp = $this->login('admin@pluto.example');
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $noOtp, $this->books->id), 403, 'not_a_member');
        $this->assertProblem($this->api('POST', "/api/v1/platform/companies/{$this->books->id}/support-access", $noOtp, null, ['reason' => 'x']), 403, 'platform_two_factor_required');

        config(['pluto.platform_admins' => []]); // removed from the list: the open grant no longer opens anything
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $this->admin, $this->books->id), 403, 'not_a_member');
    }

    public function test_validation(): void
    {
        $this->assertProblem($this->open(['minutes' => 5]), 422, 'validation_failed');
        $this->assertProblem($this->open(['reason' => 'x', 'minutes' => 999]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/platform/companies/999999/support-access', $this->admin, null, ['reason' => 'x']), 404, 'not_found');
    }
}
