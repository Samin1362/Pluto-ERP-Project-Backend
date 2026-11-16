<?php

namespace Tests\Feature\PlatformConsole;

use App\Domain\Access\JwtService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Announcements (K17 + S0 banner; PEP-513): the software owner posts; everyone sees it until they close it. */
class AnnouncementsTest extends TestCase
{
    use BuildsMasterData;

    private string $admin;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany(); // Meghna Traders Ltd.
        $this->makeUser('admin@pluto.example');
        config(['pluto.platform_admins' => ['admin@pluto.example']]);
        $t = $this->login('admin@pluto.example');
        $this->admin = app(JwtService::class)->issue(User::where('email', 'admin@pluto.example')->firstOrFail(), (int) $this->claims($t)['sid'], ['pwd', 'otp'])['access_token'];
        $this->owner = $this->login('owner@meghna.example');
    }

    private function announce(array $body)
    {
        return $this->api('POST', '/api/v1/platform/announcements', $this->admin, null, $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function shown(?string $token = null): array
    {
        return array_column($this->api('GET', '/api/v1/announcements', $token ?? $this->owner)->assertOk()->json('data'), 'title');
    }

    public function test_the_owner_posts_and_everyone_sees_it_until_they_close_it(): void
    {
        $a = $this->announce(['title' => 'Update tonight at 11 pm', 'body' => 'Pluto will be unavailable for about 10 minutes.', 'tone' => 'warning'])->assertCreated();
        $a->assertJsonPath('data.tone', 'warning')->assertJsonPath('data.is_active', true)->assertJsonPath('data.version', 1);
        $this->assertSame(['Update tonight at 11 pm'], $this->shown());

        // Rival company, another person: sees it too (platform-wide).
        $this->provisionedCompany('Rupsha Fisheries', 'owner@rupsha.example');
        $rupsha = $this->login('owner@rupsha.example');
        $this->assertSame(['Update tonight at 11 pm'], $this->shown($rupsha));

        $this->api('POST', '/api/v1/announcements/'.$a->json('data.id').'/dismiss', $this->owner)->assertNoContent();
        $this->api('POST', '/api/v1/announcements/'.$a->json('data.id').'/dismiss', $this->owner)->assertNoContent(); // twice is fine
        $this->assertSame([], $this->shown());
        $this->assertSame(['Update tonight at 11 pm'], $this->shown($rupsha), 'closing is per person');
        $this->assertSame(1, DB::table('audit_logs')->where('auditable_type', 'announcements')->where('action', 'created')->count());
    }

    public function test_scheduled_stopped_and_deleted_announcements_are_not_shown(): void
    {
        $this->announce(['title' => 'Later', 'tone' => 'info', 'starts_at' => now()->addDay()->toIso8601String()])->assertCreated();
        $this->announce(['title' => 'Over', 'tone' => 'info', 'starts_at' => now()->subDays(2)->toIso8601String(), 'ends_at' => now()->subDay()->toIso8601String()])->assertCreated();
        $live = $this->announce(['title' => 'New: LC items', 'tone' => 'success'])->assertCreated();
        $this->assertSame(['New: LC items'], $this->shown());

        $id = $live->json('data.id');
        $this->api('PUT', "/api/v1/platform/announcements/{$id}", $this->admin, null, ['title' => 'New: LC items', 'tone' => 'success', 'is_active' => false], ['If-Match' => 'W/"1"'])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.version', 2);
        $this->assertSame([], $this->shown());
        $this->assertProblem($this->api('PUT', "/api/v1/platform/announcements/{$id}", $this->admin, null, ['title' => 'x', 'tone' => 'info'], ['If-Match' => 'W/"1"']), 409, 'version_conflict');

        $this->assertCount(3, $this->api('GET', '/api/v1/platform/announcements', $this->admin)->assertOk()->json('data'));
        $this->api('DELETE', "/api/v1/platform/announcements/{$id}", $this->admin)->assertNoContent();
        $this->assertCount(2, $this->api('GET', '/api/v1/platform/announcements', $this->admin)->json('data'));
    }

    public function test_only_the_software_owner_manages_them_and_input_is_checked(): void
    {
        $this->assertProblem($this->api('POST', '/api/v1/platform/announcements', $this->owner, null, ['title' => 'x', 'tone' => 'info'], ['Idempotency-Key' => (string) Str::uuid()]), 403, 'platform_admin_only');
        $this->assertProblem($this->api('GET', '/api/v1/platform/announcements', $this->owner), 403, 'platform_admin_only');
        $this->assertProblem($this->announce(['title' => '', 'tone' => 'loud']), 422, 'validation_failed');
        $this->assertProblem($this->announce(['title' => 'x', 'tone' => 'info', 'starts_at' => now()->toIso8601String(), 'ends_at' => now()->subHour()->toIso8601String()]), 422, 'validation_failed');
    }
}
