<?php

namespace Tests\Feature\PlatformConsole;

use App\Domain\Access\CompanyService;
use App\Domain\Access\JwtService;
use App\Domain\Parties\PartyService;
use App\Models\Ledger;
use App\Models\User;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Owner access (K17; PEP-496): the software owner joins any customer company as a real member with its Owner role,
 * works in it like any Owner, and leaves again. The owners are told, the audit log keeps it, the customer's seats are
 * not used, and one company's data stays out of reach of another.
 */
class OwnerAccessTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private const REASON = 'Customer asked us to correct the opening balances';

    private string $admin;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany(); // Meghna Traders Ltd., owner@meghna.example
        $this->adminId = $this->makeUser('admin@pluto.example')->id;
        config(['pluto.platform_admins' => ['admin@pluto.example']]);
        $this->admin = $this->otp($this->login('admin@pluto.example'), 'admin@pluto.example');
    }

    private function otp(string $token, string $email): string
    {
        return app(JwtService::class)->issue(User::where('email', $email)->firstOrFail(), (int) $this->claims($token)['sid'], ['pwd', 'otp'])['access_token'];
    }

    private function join(?int $companyId = null, array $body = ['reason' => self::REASON], ?string $token = null, ?string $key = null)
    {
        $companyId ??= $this->books->id;

        return $this->api('POST', "/api/v1/platform/companies/{$companyId}/owner-access", $token ?? $this->admin, null, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function leave(?int $companyId = null, ?string $token = null)
    {
        $companyId ??= $this->books->id;

        return $this->api('DELETE', "/api/v1/platform/companies/{$companyId}/owner-access", $token ?? $this->admin);
    }

    private function membership(?int $companyId = null): ?object
    {
        return DB::table('company_users as m')->join('roles as r', 'r.id', '=', 'm.role_id')->where('m.company_id', $companyId ?? $this->books->id)
            ->where('m.user_id', $this->adminId)->first(['m.id', 'm.status', 'm.joined_via', 'r.slug']);
    }

    public function test_an_admin_joins_a_company_as_owner_and_works_in_it(): void
    {
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $this->admin, $this->books->id), 403, 'not_a_member');

        $r = $this->join()->assertCreated();
        $r->assertJsonPath('data.company_id', $this->books->id)->assertJsonPath('data.company_name', 'Meghna Traders Ltd.')->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.already_member', false);
        $this->assertNotNull($r->json('data.joined_at'));

        $m = $this->membership();
        $this->assertSame(['active', 'platform_admin', 'owner'], [$m->status, $m->joined_via, $m->slug]);

        // Every existing code path works for a real membership: master data, and posting to the books.
        $c = $this->books->id;
        $party = $this->api('POST', '/api/v1/parties', $this->admin, $c, ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711999888'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $this->assertSame($this->adminId, (int) DB::table('parties')->where('id', $party->json('data.id'))->value('created_by'));
        $this->useBooks();
        $ledger = fn (string $code) => (int) Ledger::where('code', $code)->value('id');
        $voucher = $this->api('POST', '/api/v1/vouchers', $this->admin, $c, ['entry_type' => 'journal', 'action' => 'post', 'date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'narration' => 'Depreciation for the month', 'lines' => [['ledger_id' => $ledger('6501'), 'debit' => '5000.00'], ['ledger_id' => $ledger('1509'), 'credit' => '5000.00']]],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $voucher->assertJsonPath('data.status', 'posted')->assertJsonPath('data.total_debit', '5000.00');
        $this->useBooks();
        $this->assertBooksAreConsistent();

        $me = $this->api('GET', '/api/v1/me', $this->admin, $c)->assertOk();
        $me->assertJsonPath('data.active_company_id', $c)->assertJsonPath('data.support_access', null);
        $this->assertContains($c, array_column($me->json('data.companies'), 'company_id'));
        $this->assertContains('settings.users', $me->json('data.permissions'));
    }

    public function test_the_owners_are_told_and_the_audit_log_keeps_the_reason(): void
    {
        $this->join()->assertCreated();

        $owner = User::where('email', 'owner@meghna.example')->value('id');
        $note = DB::table('notifications')->where('company_id', $this->books->id)->where('user_id', $owner)->where('event_key', 'owner_access')->first();
        $this->assertNotNull($note, 'the owner is told');
        $this->assertStringContainsString('Pluto support (Admin) joined your company as Owner', $note->title);
        $this->assertStringContainsString('Reason: '.self::REASON, $note->body);
        $this->assertSame(0, DB::table('notifications')->where('user_id', $this->adminId)->count(), 'the admin is not told about themself');

        $log = DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'platform_owner_access_started')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->adminId, (int) $log->user_id);
        $this->assertSame(self::REASON, json_decode($log->new_values, true)['reason']);
    }

    public function test_the_company_sees_who_it_is_and_no_seat_is_used(): void
    {
        $seats = CompanyService::seatsUsed($this->books->id);
        $this->join()->assertCreated();
        $this->assertSame($seats, CompanyService::seatsUsed($this->books->id), 'Pluto support is not the customer\'s seat');

        $owner = $this->login('owner@meghna.example');
        $rows = collect($this->api('GET', '/api/v1/users', $owner, $this->books->id)->assertOk()->json('data'))->keyBy('email');
        $this->assertTrue($rows['admin@pluto.example']['platform_admin']);
        $this->assertSame('Owner', $rows['admin@pluto.example']['role_name']);
        $this->assertFalse($rows['owner@meghna.example']['platform_admin']);

        $list = $this->api('GET', '/api/v1/platform/companies', $this->admin)->assertOk();
        $list->assertJsonPath('data.0.owner_access', true)->assertJsonPath('data.0.members', 1)->assertJsonPath('data.0.owner_email', 'owner@meghna.example');

        // The customer's Owner may send Pluto support away; the admin can come back from the console.
        $this->api('POST', "/api/v1/users/{$this->membership()->id}/deactivate", $owner, $this->books->id)->assertOk()->assertJsonPath('data.status', 'deactivated');
        $admin = $this->otp($this->login('admin@pluto.example'), 'admin@pluto.example'); // deactivation ended the admin's sign-ins
        $this->assertProblem($this->api('GET', '/api/v1/company', $admin, $this->books->id), 403, 'not_a_member');
        $this->api('GET', '/api/v1/platform/companies', $admin)->assertJsonPath('data.0.owner_access', false);
        $this->join(token: $admin)->assertCreated()->assertJsonPath('data.already_member', true);
        $this->api('GET', '/api/v1/company', $admin, $this->books->id)->assertOk();
        $this->assertSame(1, DB::table('company_users')->where('company_id', $this->books->id)->where('user_id', $this->adminId)->count());
    }

    public function test_leaving_ends_the_membership_and_joining_again_restores_it(): void
    {
        $this->join()->assertCreated();
        $this->leave()->assertNoContent();

        $this->assertSame('deactivated', $this->membership()->status);
        $this->assertProblem($this->api('GET', '/api/v1/parties?type=customer', $this->admin, $this->books->id), 403, 'not_a_member');
        $this->assertProblem($this->api('POST', '/api/v1/parties', $this->admin, $this->books->id, ['party_type' => 'customer', 'name' => 'X', 'mobile' => '01711999888'],
            ['Idempotency-Key' => (string) Str::uuid()]), 403, 'not_a_member');
        $this->assertTrue(DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'platform_owner_access_ended')->where('user_id', $this->adminId)->exists());
        $this->api('GET', '/api/v1/platform/companies', $this->admin)->assertJsonPath('data.0.owner_access', false);
        $this->leave()->assertNoContent(); // nothing to end: still fine
        $this->assertSame(1, DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'platform_owner_access_ended')->count());

        $this->join()->assertCreated()->assertJsonPath('data.already_member', true);
        $m = $this->membership();
        $this->assertSame(['active', 'platform_admin', 'owner'], [$m->status, $m->joined_via, $m->slug]);
        $this->api('GET', '/api/v1/parties?type=customer', $this->admin, $this->books->id)->assertOk();
        $this->assertSame(2, DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'platform_owner_access_started')->count());
    }

    public function test_the_only_active_owner_cannot_leave(): void
    {
        $this->join()->assertCreated();
        $owner = User::where('email', 'owner@meghna.example')->value('id');
        DB::table('company_users')->where('company_id', $this->books->id)->where('user_id', $owner)->update(['status' => 'deactivated']);

        $this->assertProblem($this->leave(), 409, 'last_owner');
        $this->assertSame('active', $this->membership()->status);
    }

    public function test_only_a_platform_admin_on_a_two_step_sign_in(): void
    {
        $owner = $this->login('owner@meghna.example');
        $this->assertProblem($this->join(token: $owner), 403, 'platform_admin_only');
        $this->assertProblem($this->leave(token: $owner), 403, 'platform_admin_only');
        $this->assertProblem($this->join(token: $this->otp($owner, 'owner@meghna.example')), 403, 'platform_admin_only');

        $noOtp = $this->login('admin@pluto.example');
        $this->assertProblem($this->join(token: $noOtp), 403, 'platform_two_factor_required');
        $this->assertProblem($this->leave(token: $noOtp), 403, 'platform_two_factor_required');
        $this->assertProblem($this->api('POST', "/api/v1/platform/companies/{$this->books->id}/owner-access", null, null, ['reason' => 'x']), 401, 'unauthenticated');
        $this->assertNull($this->membership());
    }

    public function test_a_suspended_company_is_reactivated_first(): void
    {
        $this->api('POST', "/api/v1/platform/companies/{$this->books->id}/suspend", $this->admin, null, ['reason' => 'Subscription unpaid'])->assertOk();
        $this->assertProblem($this->join(), 409, 'invalid_transition');
        $this->assertNull($this->membership());

        $this->api('POST', "/api/v1/platform/companies/{$this->books->id}/reactivate", $this->admin, null, ['reason' => 'Paid'])->assertOk();
        $this->join()->assertCreated();
    }

    public function test_validation_and_unknown_company(): void
    {
        $this->assertProblem($this->join(body: []), 422, 'validation_failed');
        $this->assertProblem($this->join(body: ['reason' => str_repeat('x', 256)]), 422, 'validation_failed');
        $this->assertProblem($this->join(body: ['reason' => 'x', 'minutes' => 30]), 422, 'validation_failed');
        $this->assertProblem($this->join(999999), 404, 'not_found');
        $this->assertProblem($this->leave(999999), 404, 'not_found');
    }

    public function test_joining_one_company_opens_no_other(): void
    {
        $padma = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('owner@padma.example'));
        $this->useBooks();
        $meghnaParty = $this->customer()->id;
        app(CurrentCompany::class)->set($padma, null);
        $padmaParty = app(PartyService::class)->create(['party_type' => 'customer', 'name' => 'Padma buyer', 'mobile' => '01811000111', 'default_currency_code' => 'BDT'])['party']->id;

        $this->join()->assertCreated(); // Meghna only

        $this->api('GET', "/api/v1/parties/{$meghnaParty}", $this->admin, $this->books->id)->assertOk();
        $this->assertProblem($this->api('GET', "/api/v1/parties/{$padmaParty}", $this->admin, $this->books->id), 404, 'not_found');
        $this->assertProblem($this->api('GET', "/api/v1/parties/{$padmaParty}", $this->admin, $padma->id), 403, 'not_a_member');
        $this->assertNull($this->membership($padma->id));
        // The customers never see each other either.
        $this->assertProblem($this->api('GET', "/api/v1/parties/{$meghnaParty}", $this->login('owner@padma.example'), $padma->id), 404, 'not_found');
    }

    public function test_an_idempotency_key_replays_the_join(): void
    {
        $key = (string) Str::uuid();
        $a = $this->join(key: $key)->assertCreated();
        $b = $this->join(key: $key)->assertCreated();
        $this->assertEquals($a->json('data'), $b->json('data'));
        $this->assertSame('true', $b->headers->get('Idempotency-Replayed'));
        $this->assertSame(1, DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'platform_owner_access_started')->count());
        $this->assertProblem($this->join(body: ['reason' => 'Another reason'], key: $key), 422, 'idempotency_key_reused');

        // A fresh key while already an active Owner changes and announces nothing.
        $this->join()->assertCreated()->assertJsonPath('data.already_member', true);
        $this->assertSame(1, DB::table('notifications')->where('company_id', $this->books->id)->where('event_key', 'owner_access')->count());
    }

    public function test_an_earlier_ordinary_membership_becomes_owner(): void
    {
        $this->addMember($this->books, User::findOrFail($this->adminId), 'viewer');
        $this->join()->assertCreated()->assertJsonPath('data.already_member', true);
        $m = $this->membership();
        $this->assertSame(['active', 'platform_admin', 'owner'], [$m->status, $m->joined_via, $m->slug]);
    }
}
