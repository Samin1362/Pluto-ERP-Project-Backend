<?php

namespace Tests\Feature\Settings;

use App\Mail\InvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * /users, /invitations, /roles, /permissions (K8–K11, S3; BR-059, BR-060, BR-061; R-10, R-18; TC-AUTH-011, TC-AUTH-013,
 * TC-AUTH-014, TC-AUTH-019; backlog P15.1-BE-04..06).
 */
class MembersAndRolesTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        $h = $headers + ($method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $h);
        $this->useBooks();

        return $r;
    }

    private function role(string $slug): int
    {
        return (int) DB::table('roles')->where('company_id', $this->books->id)->where('slug', $slug)->value('id');
    }

    private function member(string $email): array
    {
        return collect($this->req('GET', '/api/v1/users?per_page=100')->json('data'))->firstWhere('email', $email);
    }

    /** Invites and returns the raw token from the queued e-mail. */
    private function invite(string $email, string $role, array $extra = []): string
    {
        $this->req('POST', '/api/v1/invitations', ['email' => $email, 'role_id' => $this->role($role)] + $extra)->assertCreated();
        $link = null;
        Mail::assertQueued(InvitationMail::class, function (InvitationMail $m) use ($email, &$link) {
            if ($m->hasTo($email)) {
                $link = $m->link;
            }

            return $m->hasTo($email);
        });

        return Str::afterLast($link, '/');
    }

    public function test_t_c_auth_014_an_invitation_creates_the_user_who_joins_with_the_role_and_branches(): void
    {
        $branch = DB::table('branches')->where('company_id', $this->books->id)->value('id');
        $token = $this->invite('rahim@meghna.example', 'sales', ['name' => 'Rahim Uddin', 'branch_ids' => [$branch]]);
        $this->assertProblem($this->req('POST', '/api/v1/invitations', ['email' => 'Rahim@meghna.example', 'role_id' => $this->role('sales')]), 409, 'duplicate');
        $open = $this->req('GET', '/api/v1/invitations')->assertOk()->json('data');
        $this->assertSame(['rahim@meghna.example', 'pending'], [$open[0]['email'], $open[0]['status']]);
        $this->req('GET', '/api/v1/company')->assertJsonPath('data.seats_used', 2);

        $this->getJson("/api/v1/invitations/{$token}")->assertOk()->assertJsonPath('data.company_name', 'Meghna Traders Ltd.')
            ->assertJsonPath('data.role_name', 'Sales staff')->assertJsonPath('data.existing_user', false);
        $this->assertProblem($this->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Rahim Uddin', 'password' => 'short', 'password_confirmation' => 'short']), 422, 'validation_failed');
        $r = $this->postJson("/api/v1/invitations/{$token}/accept", ['name' => 'Rahim Uddin', 'password' => 'Green-Paddy-Field-9', 'password_confirmation' => 'Green-Paddy-Field-9'])->assertOk();
        $r->assertJsonPath('data.status', 'authenticated')->assertJsonPath('data.user.email', 'rahim@meghna.example')->assertCookie(config('pluto.refresh.cookie'));
        $m = $this->member('rahim@meghna.example');
        $this->assertSame(['active', 'Sales staff', [$branch]], [$m['status'], $m['role_name'], $m['branch_ids']]);
        $this->api('GET', '/api/v1/company/settings', $r->json('data.access_token'), $this->books->id)->assertOk();
        $this->assertProblem($this->getJson("/api/v1/invitations/{$token}"), 410, 'gone');
        $this->assertProblem($this->getJson('/api/v1/invitations/'.str_repeat('a', 48)), 404, 'not_found');
    }

    public function test_an_existing_user_confirms_with_their_password_and_invitations_expire_resend_and_revoke(): void
    {
        $other = $this->makeCompany('Padma Foods Ltd.', $this->makeUser('karim@padma.example'));
        $this->useBooks();
        $token = $this->invite('karim@padma.example', 'accountant');
        $this->getJson("/api/v1/invitations/{$token}")->assertJsonPath('data.existing_user', true);
        $this->assertProblem($this->postJson("/api/v1/invitations/{$token}/accept", ['password' => 'not-my-password']), 422, 'validation_failed');
        $this->postJson("/api/v1/invitations/{$token}/accept", ['password' => self::PASSWORD])->assertOk();
        $this->assertCount(2, $this->api('GET', '/api/v1/me', $this->login('karim@padma.example'))->json('data.companies'));
        $this->assertGreaterThan(0, $other->id);

        $old = $this->invite('late@meghna.example', 'viewer');
        $id = $this->req('GET', '/api/v1/invitations')->json('data.0.id');
        $this->travel(8)->days();
        $this->assertProblem($this->getJson("/api/v1/invitations/{$old}"), 410, 'gone');
        $this->req('GET', '/api/v1/invitations')->assertJsonPath('data.0.status', 'expired');
        $this->req('POST', "/api/v1/invitations/{$id}/resend")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertProblem($this->getJson("/api/v1/invitations/{$old}"), 404, 'not_found', 'the old link stops working');
        $this->req('DELETE', "/api/v1/invitations/{$id}")->assertNoContent();
        $this->assertProblem($this->req('DELETE', "/api/v1/invitations/{$id}"), 404, 'not_found');
        $this->assertSame([], $this->req('GET', '/api/v1/invitations')->json('data'));
    }

    public function test_b_r_059_seats_are_checked_on_invite_accept_and_reactivate(): void
    {
        DB::table('companies')->where('id', $this->books->id)->update(['seat_limit' => 2]);
        $this->userWithRole('viewer');
        $this->assertProblem($this->req('POST', '/api/v1/invitations', ['email' => 'third@meghna.example', 'role_id' => $this->role('sales')]), 409, 'seat_limit_reached');
        $viewer = $this->member('viewer@meghna.example');
        $this->req('POST', "/api/v1/users/{$viewer['id']}/deactivate")->assertOk()->assertJsonPath('data.status', 'deactivated');
        $this->invite('third@meghna.example', 'sales');
        $this->assertProblem($this->req('POST', "/api/v1/users/{$viewer['id']}/reactivate"), 409, 'seat_limit_reached');
    }

    public function test_t_c_auth_011_013_deactivation_ends_the_session_and_the_last_owner_is_protected(): void
    {
        $this->userWithRole('accountant');
        $acc = $this->login('accountant@meghna.example');
        $this->api('GET', '/api/v1/company/settings', $acc, $this->books->id)->assertOk();
        $m = $this->member('accountant@meghna.example');
        $this->assertSame(['Accountant', false], [$m['role_name'], $m['two_factor_enabled']]);
        $this->req('GET', "/api/v1/users/{$m['id']}")->assertOk()->assertJsonStructure(['data' => ['permissions', 'recent_activity']]);
        $this->req('POST', "/api/v1/users/{$m['id']}/deactivate")->assertOk();
        $this->assertProblem($this->api('GET', '/api/v1/company/settings', $acc, $this->books->id), 401, 'token_invalid');
        $this->assertProblem($this->api('GET', '/api/v1/company/settings', $this->login('accountant@meghna.example'), $this->books->id), 403, 'not_a_member', 'signs in, but not into this company');
        $this->req('POST', "/api/v1/users/{$m['id']}/reactivate")->assertOk()->assertJsonPath('data.status', 'active');

        $owner = $this->member('owner@meghna.example');
        $this->assertProblem($this->req('PATCH', "/api/v1/users/{$owner['id']}", ['role_id' => $this->role('finance')], ['If-Match' => "W/\"{$owner['version']}\""]), 409, 'last_owner');
        $this->assertProblem($this->req('POST', "/api/v1/users/{$owner['id']}/deactivate"), 422, 'validation_failed');
        $this->req('PATCH', "/api/v1/users/{$m['id']}", ['role_id' => $this->role('owner')], ['If-Match' => 'W/"3"'])->assertOk()->assertJsonPath('data.role_name', 'Owner');
        $this->req('PATCH', "/api/v1/users/{$owner['id']}", ['role_id' => $this->role('finance')], ['If-Match' => "W/\"{$owner['version']}\""])->assertOk();

        // A Finance manager given settings.users still cannot hand out the Owner role.
        DB::table('role_permissions')->insert(['role_id' => $this->role('finance'), 'permission_id' => DB::table('permissions')->where('key', 'settings.users')->value('id')]);
        DB::table('roles')->where('id', $this->role('finance'))->increment('lock_version');
        $this->userWithRole('finance');
        $this->userWithRole('viewer');
        $v = $this->member('viewer@meghna.example');
        $this->assertProblem($this->req('PATCH', "/api/v1/users/{$v['id']}", ['role_id' => $this->role('owner')], ['If-Match' => "W/\"{$v['version']}\""], $this->login('finance@meghna.example')),
            403, 'permission_denied');
    }

    public function test_t_c_auth_019_roles_editor(): void
    {
        $cat = $this->req('GET', '/api/v1/permissions')->assertOk()->json('data.modules');
        $sales = collect($cat)->firstWhere('module', 'sales');
        $this->assertSame(['view', 'create', 'edit', 'approve', 'delete'], array_column($sales['permissions'], 'action'));
        $this->assertTrue(collect(collect($cat)->firstWhere('module', 'inventory')['permissions'])->firstWhere('action', 'view_costs')['special']);

        $owner = collect($this->req('GET', '/api/v1/roles')->json('data'))->firstWhere('slug', 'owner');
        $this->assertSame([true, null, 1], [$owner['is_system'], $owner['payment_approval_limit'], $owner['members_count']]);
        $this->assertProblem($this->req('PUT', "/api/v1/roles/{$owner['id']}", ['name' => 'Owner', 'permissions' => [], 'payment_approval_limit' => '0', 'journal_approval_limit' => '0'],
            ['If-Match' => 'W/"1"']), 403, 'system_role');

        $this->assertProblem($this->req('POST', '/api/v1/roles', ['name' => 'Cashier', 'permissions' => ['banking.view']]), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['payment_approval_limit', 'journal_approval_limit']]);
        $r = $this->req('POST', '/api/v1/roles', ['name' => 'Cashier', 'permissions' => ['banking.view', 'banking.create'], 'payment_approval_limit' => '25000.00',
            'journal_approval_limit' => '0.00', 'copy_from_role_id' => $this->role('viewer')])->assertCreated();
        $this->assertContains('sales.view', $r->json('data.permissions'), 'copied from Viewer');
        $this->assertContains('banking.create', $r->json('data.permissions'));
        $this->assertProblem($this->req('POST', '/api/v1/roles', ['name' => 'Cashier', 'permissions' => [], 'payment_approval_limit' => '1', 'journal_approval_limit' => '1']), 422, 'validation_failed');

        $this->userWithRole('cashier', 'cash@meghna.example');
        $cash = $this->login('cash@meghna.example');
        $this->api('GET', '/api/v1/banking/overview', $cash, $this->books->id)->assertOk();
        $this->req('PUT', '/api/v1/roles/'.$r->json('data.id'), ['name' => 'Cashier', 'permissions' => ['sales.view'], 'payment_approval_limit' => '25000.00', 'journal_approval_limit' => '0.00'],
            ['If-Match' => "W/\"{$r->json('data.version')}\""])->assertOk()->assertJsonPath('data.permissions', ['sales.view']);
        $this->assertProblem($this->api('GET', '/api/v1/banking/overview', $cash, $this->books->id), 403, 'permission_denied', 'the next request sees the change');
        $this->assertProblem($this->req('DELETE', '/api/v1/roles/'.$r->json('data.id')), 409, 'role_in_use');
        $m = $this->member('cash@meghna.example');
        $this->req('PATCH', "/api/v1/users/{$m['id']}", ['role_id' => $this->role('viewer')], ['If-Match' => "W/\"{$m['version']}\""])->assertOk();
        $this->req('DELETE', '/api/v1/roles/'.$r->json('data.id'))->assertNoContent();

        $this->userWithRole('finance');
        $this->assertProblem($this->req('GET', '/api/v1/roles', token: $this->login('finance@meghna.example')), 403, 'permission_denied');
    }
}
