<?php

namespace Tests\Feature\PlatformConsole;

use App\Domain\Access\JwtService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Pluto plans (K17; PEP-498; owner decision 14 Nov 2026): the software owner edits plans and puts each company on one,
 * with its own exceptions. A module the plan leaves out answers 403 module_not_in_plan; limits block adding more but
 * never remove anyone; switching a module off deletes nothing.
 */
class PlansTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $admin;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany(); // Meghna Traders Ltd., owner@meghna.example
        $this->makeUser('admin@pluto.example');
        config(['pluto.platform_admins' => ['admin@pluto.example']]);
        $token = $this->login('admin@pluto.example');
        $this->admin = app(JwtService::class)->issue(User::where('email', 'admin@pluto.example')->firstOrFail(), (int) $this->claims($token)['sid'], ['pwd', 'otp'])['access_token'];
        $this->owner = $this->login('owner@meghna.example');
    }

    private function plan(string $slug): object
    {
        return DB::table('plans')->where('slug', $slug)->first();
    }

    private function assign(string $slug, ?array $overrides = null, ?int $company = null)
    {
        $body = ['plan_id' => $this->plan($slug)->id] + ($overrides === null ? [] : ['overrides' => $overrides]);

        return $this->api('POST', '/api/v1/platform/companies/'.($company ?? $this->books->id).'/plan', $this->admin, null, $body);
    }

    private function asOwner(string $method, string $uri, array $data = [])
    {
        $r = $this->api($method, $uri, $this->owner, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    public function test_the_three_starting_plans_exist_and_a_company_without_a_plan_has_everything(): void
    {
        $r = $this->api('GET', '/api/v1/platform/plans', $this->admin)->assertOk();
        $this->assertSame(['basic', 'business', 'import_pro'], array_column($r->json('data'), 'slug'));
        $r->assertJsonPath('data.0.max_users', 3)->assertJsonPath('data.0.max_branches', 1)->assertJsonPath('data.0.modules', [])
            ->assertJsonPath('data.1.modules', ['lc_management', 'payroll'])->assertJsonPath('data.2.max_branches', null)->assertJsonPath('data.2.modules', ['lc_management', 'lc_finance', 'payroll']);

        $this->asOwner('GET', '/api/v1/me')->assertJsonPath('data.plan_name', 'Import Pro')->assertJsonPath('data.modules', ['lc_finance', 'lc_management', 'payroll']);
        $this->asOwner('GET', '/api/v1/letters-of-credit')->assertOk();
        $row = collect($this->api('GET', '/api/v1/platform/companies', $this->admin)->json('data'))->firstWhere('id', $this->books->id);
        $this->assertSame('import_pro', $row['plan']['slug']);
        $this->assertSame(['lc_finance', 'lc_management', 'payroll'], $row['effective']['modules']);
    }

    public function test_only_a_platform_admin_on_two_step_manages_plans(): void
    {
        $this->assertProblem($this->api('GET', '/api/v1/platform/plans', $this->owner), 403, 'platform_admin_only');
        $this->assertProblem($this->api('POST', '/api/v1/platform/companies/'.$this->books->id.'/plan', $this->owner, null, ['plan_id' => $this->plan('basic')->id]), 403, 'platform_admin_only');
        $this->assertProblem($this->api('GET', '/api/v1/platform/plans', $this->login('admin@pluto.example')), 403, 'platform_two_factor_required');
    }

    public function test_plans_are_created_and_changed_with_their_rules(): void
    {
        $body = ['slug' => 'starter', 'name' => 'Starter', 'max_users' => 2, 'max_branches' => 1, 'modules' => []];
        $r = $this->api('POST', '/api/v1/platform/plans', $this->admin, null, $body, ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $r->assertJsonPath('data.slug', 'starter')->assertJsonPath('data.is_active', true)->assertJsonPath('data.companies', 0)->assertJsonPath('data.version', 1);
        $id = $r->json('data.id');

        $this->assertProblem($this->api('POST', '/api/v1/platform/plans', $this->admin, null, $body, ['Idempotency-Key' => (string) Str::uuid()]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/platform/plans', $this->admin, null, ['slug' => 'x', 'name' => 'X', 'max_users' => 1, 'max_branches' => null,
            'modules' => ['lc_finance']], ['Idempotency-Key' => (string) Str::uuid()]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/platform/plans', $this->admin, null, ['slug' => 'y', 'name' => 'Y', 'max_users' => 1, 'max_branches' => null,
            'modules' => ['hr']], ['Idempotency-Key' => (string) Str::uuid()]), 422, 'validation_failed'); // payroll is a module since PEP-520

        $this->api('PUT', "/api/v1/platform/plans/{$id}", $this->admin, null, ['max_users' => 4] + $body, ['If-Match' => 'W/"1"'])->assertOk()
            ->assertJsonPath('data.max_users', 4)->assertJsonPath('data.version', 2);
        $this->assertProblem($this->api('PUT', "/api/v1/platform/plans/{$id}", $this->admin, null, $body, ['If-Match' => 'W/"1"']), 409, 'version_conflict');
    }

    public function test_basic_hides_lc_and_business_hides_lc_finance_without_deleting_anything(): void
    {
        $this->asOwner('GET', '/api/v1/bank-facilities')->assertOk(); // import_pro: everything

        $this->assign('basic')->assertOk()->assertJsonPath('data.plan.slug', 'basic')->assertJsonPath('data.effective.modules', [])
            ->assertJsonPath('data.effective.max_users', 3)->assertJsonPath('data.effective.max_branches', 1);
        $p = $this->asOwner('GET', '/api/v1/letters-of-credit');
        $this->assertProblem($p, 403, 'module_not_in_plan');
        $this->assertSame('lc_management', $p->json('module'));
        $this->assertProblem($this->asOwner('GET', '/api/v1/lc/overview'), 403, 'module_not_in_plan');
        $this->assertProblem($this->asOwner('GET', '/api/v1/lc-loans'), 403, 'module_not_in_plan');
        $this->asOwner('GET', '/api/v1/me')->assertJsonPath('data.plan_name', 'Basic')->assertJsonPath('data.modules', []);
        $this->asOwner('GET', '/api/v1/company')->assertJsonPath('data.plan_name', 'Basic')->assertJsonPath('data.max_branches', 1)->assertJsonPath('data.seat_limit', 3);
        $this->asOwner('GET', '/api/v1/parties?type=customer')->assertOk(); // the rest of Pluto is untouched

        $this->assign('business')->assertOk();
        $this->asOwner('GET', '/api/v1/letters-of-credit')->assertOk();
        $this->assertProblem($this->asOwner('GET', '/api/v1/lc-loans'), 403, 'module_not_in_plan');
        $this->assertProblem($this->asOwner('GET', '/api/v1/bank-facilities'), 403, 'module_not_in_plan');

        // A company exception switches LC finance on for this company only.
        $this->assign('business', ['modules_on' => ['lc_finance']])->assertOk()->assertJsonPath('data.effective.modules', ['lc_finance', 'lc_management', 'payroll'])
            ->assertJsonPath('data.plan_overrides.modules_on', ['lc_finance']);
        $this->asOwner('GET', '/api/v1/lc-loans')->assertOk();
    }

    public function test_a_company_plan_change_is_audited_and_its_owners_are_told(): void
    {
        $this->assign('business', ['max_users' => 12])->assertOk()->assertJsonPath('data.effective.max_users', 12);
        $a = DB::table('audit_logs')->where('company_id', $this->books->id)->where('action', 'plan_changed')->first();
        $this->assertNotNull($a);
        $this->assertSame('business', json_decode($a->new_values, true)['plan']);
        $n = DB::table('notifications')->where('company_id', $this->books->id)->where('event_key', 'plan_changed')->first();
        $this->assertSame('Your Pluto plan is now Business', $n->title);
        $this->assertStringContainsString('Up to 12 users', $n->body);
        $this->assertSame(12, (int) DB::table('companies')->where('id', $this->books->id)->value('seat_limit'));

        $this->assertProblem($this->assign('business', null, 999999), 404, 'not_found');
        $this->assertProblem($this->api('POST', '/api/v1/platform/companies/'.$this->books->id.'/plan', $this->admin, null, ['plan_id' => 999999]), 422, 'validation_failed');
    }

    public function test_lowering_the_user_limit_removes_nobody_but_blocks_new_users(): void
    {
        $this->userWithRole('viewer');
        $this->userWithRole('accountant');
        $this->userWithRole('sales');
        $this->assign('basic')->assertOk(); // 4 people, 3 allowed
        $this->assertSame(4, DB::table('company_users')->where('company_id', $this->books->id)->where('status', 'active')->count());
        $this->asOwner('GET', '/api/v1/parties?type=customer')->assertOk();
        $role = (int) DB::table('roles')->where('company_id', $this->books->id)->where('slug', 'viewer')->value('id');
        $this->assertProblem($this->asOwner('POST', '/api/v1/invitations', ['email' => 'new@meghna.example', 'role_id' => $role]), 409, 'seat_limit_reached');

        // Changing the plan itself moves every company on it.
        $basic = $this->plan('basic');
        $this->api('PUT', "/api/v1/platform/plans/{$basic->id}", $this->admin, null, ['slug' => 'basic', 'name' => 'Basic', 'max_users' => 6, 'max_branches' => 1, 'modules' => []],
            ['If-Match' => 'W/"'.$basic->lock_version.'"'])->assertOk();
        $this->assertSame(6, (int) DB::table('companies')->where('id', $this->books->id)->value('seat_limit'));
        $this->asOwner('POST', '/api/v1/invitations', ['email' => 'new@meghna.example', 'role_id' => $role])->assertCreated();
    }

    public function test_the_branch_limit_blocks_a_new_branch_but_closing_one_is_allowed(): void
    {
        $this->assign('basic')->assertOk(); // the head office is the one branch allowed
        $this->assertProblem($this->asOwner('POST', '/api/v1/branches', ['code' => 'CTG', 'name' => 'Chattogram depot']), 409, 'branch_limit_reached');
        $this->assign('basic', ['max_branches' => 2])->assertOk()->assertJsonPath('data.effective.max_branches', 2)->assertJsonPath('data.branches_used', 1);
        $this->asOwner('POST', '/api/v1/branches', ['code' => 'CTG', 'name' => 'Chattogram depot'])->assertCreated();
        $this->assertProblem($this->asOwner('POST', '/api/v1/branches', ['code' => 'KHL', 'name' => 'Khulna depot']), 409, 'branch_limit_reached');
    }

    public function test_a_plan_is_deleted_only_when_no_company_is_on_it(): void
    {
        $this->assign('business')->assertOk();
        $business = $this->plan('business');
        $p = $this->api('DELETE', "/api/v1/platform/plans/{$business->id}", $this->admin);
        $this->assertProblem($p, 409, 'plan_in_use');
        $this->assertSame(1, $p->json('companies'));

        $this->assign('import_pro')->assertOk();
        $this->api('DELETE', "/api/v1/platform/plans/{$business->id}", $this->admin)->assertNoContent();
        $this->assertFalse(DB::table('plans')->where('slug', 'business')->exists());
        $this->assertSame(1, DB::table('audit_logs')->where('auditable_type', 'plans')->where('action', 'deleted')->count());
        $this->assertProblem($this->api('DELETE', "/api/v1/platform/plans/{$business->id}", $this->admin), 404, 'not_found');
        $this->assertProblem($this->api('DELETE', '/api/v1/platform/plans/'.$this->plan('basic')->id, $this->owner), 403, 'platform_admin_only');
    }

    public function test_every_lc_route_carries_its_module_check(): void
    {
        $missing = [];
        foreach (Route::getRoutes() as $r) {
            $uri = $r->uri();
            $finance = preg_match('#^api/v1/(bank-facilities|lc-loans|lc-loan-repayments|fx-revaluations)#', $uri);
            $lc = $finance || preg_match('#^api/v1/(letters-of-credit|lc/|lc-|landed-cost-allocations|reports/lc/)#', $uri);
            if (! $lc) {
                continue;
            }
            $mw = $r->gatherMiddleware();
            if (! in_array('module:lc_management', $mw, true) || ($finance && ! in_array('module:lc_finance', $mw, true))) {
                $missing[] = implode('|', $r->methods()).' '.$uri;
            }
        }
        $this->assertSame([], $missing);
    }
}
