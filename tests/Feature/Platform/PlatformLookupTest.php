<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P1.1-BE-01: GET /fiscal-years, /branches, /company/settings (05 getFiscalYears, getBranches, getCompanySettings). */
class PlatformLookupTest extends TestCase
{
    private Company $company;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->addMember($this->company, $this->makeUser('viewer@meghna.example'), 'viewer');
        $this->owner = $this->login('owner@meghna.example');
    }

    private function branch(int $companyId, string $code, string $name, bool $active = true): void
    {
        DB::table('branches')->insert(['company_id' => $companyId, 'code' => $code, 'name' => $name, 'is_active' => $active,
            'is_head_office' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_fiscal_years_list_the_current_year_with_twelve_periods(): void
    {
        $r = $this->api('GET', '/api/v1/fiscal-years', $this->owner, $this->company->id)->assertOk();

        $r->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'open')->assertJsonCount(12, 'data.0.periods')
            ->assertJsonPath('meta.total', 1);
        $fy = $r->json('data.0');
        $this->assertSame('07-01', substr($fy['start_date'], 5), 'Bangladesh financial year starts in July');
        $this->assertSame($fy['start_date'], $fy['periods'][0]['start_date']);
        $this->assertSame($fy['end_date'], $fy['periods'][11]['end_date']);
        $today = now('Asia/Dhaka')->toDateString();
        foreach ($fy['periods'] as $p) {
            $this->assertSame($p['start_date'] <= $today ? 'open' : 'future', $p['status']);
            $this->assertNull($p['locked_by']);
        }
    }

    public function test_branches_list_active_locations_with_search_sort_and_paging(): void
    {
        $this->branch($this->company->id, 'CTG', 'Chattogram depot');
        $this->branch($this->company->id, 'KHL', 'Khulna counter');
        $this->branch($this->company->id, 'OLD', 'Closed shop', false);

        $r = $this->api('GET', '/api/v1/branches', $this->owner, $this->company->id)->assertOk();
        $this->assertSame(['Chattogram depot', 'Head office', 'Khulna counter'], array_column($r->json('data'), 'name'));
        $r->assertJsonPath('data.1.is_head_office', true)->assertJsonPath('data.1.version', 1)->assertJsonPath('meta.total', 3);

        $this->api('GET', '/api/v1/branches?q=khul', $this->owner, $this->company->id)->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'KHL');
        $this->assertSame(['KHL', 'HO', 'CTG'], array_column($this->api('GET', '/api/v1/branches?sort=-code', $this->owner, $this->company->id)->json('data'), 'code'));
        $this->api('GET', '/api/v1/branches?per_page=2&page=2', $this->owner, $this->company->id)
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.current_page', 2);
    }

    public function test_unknown_or_invalid_parameters_are_rejected(): void
    {
        $c = $this->company->id;
        $this->assertProblem($this->api('GET', '/api/v1/branches?status=all', $this->owner, $c), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/branches?sort=address', $this->owner, $c), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/fiscal-years?year=2026', $this->owner, $c), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/company/settings?x=1', $this->owner, $c), 422, 'validation_failed');
    }

    public function test_company_settings_for_every_member_with_admin_fields_only_for_settings_view(): void
    {
        $r = $this->api('GET', '/api/v1/company/settings', $this->owner, $this->company->id)->assertOk()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.number_grouping', 'lakh')->assertJsonPath('data.symbol_position', 'before')
            ->assertJsonPath('data.week_start', 'saturday')->assertJsonPath('data.invoice.template', 'classic')
            ->assertJsonPath('data.require_two_factor', false)->assertJsonPath('data.backup_enabled', true)
            ->assertJsonPath('data.idle_timeout_minutes', 60)->assertJsonPath('data.version', 1);

        $viewer = $this->login('viewer@meghna.example');
        $v = $this->api('GET', '/api/v1/company/settings', $viewer, $this->company->id)->assertOk();
        $v->assertJsonPath('data.number_grouping', 'lakh');
        $this->assertArrayNotHasKey('backup_enabled', $v->json('data'));
        $this->assertArrayNotHasKey('backup_retention_days', $v->json('data'));
        $this->assertArrayNotHasKey('idle_timeout_minutes', $v->json('data'));
        // Every member can read the lookups.
        $this->api('GET', '/api/v1/fiscal-years', $viewer, $this->company->id)->assertOk();
        $this->api('GET', '/api/v1/branches', $viewer, $this->company->id)->assertOk();
    }

    public function test_lookups_need_a_company_and_never_show_another_companys_data(): void
    {
        $other = $this->makeCompany('Padma Traders Ltd.', $this->makeUser('owner@padma.example'));
        $this->branch($other->id, 'PAD', 'Padma warehouse');

        $this->assertNotContains('PAD', array_column($this->api('GET', '/api/v1/branches', $this->owner, $this->company->id)->json('data'), 'code'));
        $this->assertProblem($this->api('GET', '/api/v1/branches', $this->owner, $other->id), 403, 'not_a_member');
        $this->assertProblem($this->api('GET', '/api/v1/fiscal-years', $this->owner), 400, 'company_required');
        $this->assertProblem($this->api('GET', '/api/v1/company/settings', null, $this->company->id), 401, 'unauthenticated');
    }
}
