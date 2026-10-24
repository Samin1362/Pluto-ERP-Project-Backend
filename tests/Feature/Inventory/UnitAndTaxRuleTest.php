<?php

namespace Tests\Feature\Inventory;

use App\Domain\Access\CompanyProvisioner;
use App\Models\AuditLog;
use App\Models\Company;
use Database\Seeders\DemoTaxRuleSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GET/POST /units (D2 inline add, FR-033) and GET /tax-rules (product and document forms), backlog P3.1-BE-01.
 * Default units come from the company bootstrap; test tax rules from DemoTaxRuleSeeder (local only).
 */
class UnitAndTaxRuleTest extends TestCase
{
    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        return $this->api($method, $uri, $token ?? $this->token, $this->company->id, $data);
    }

    private function as(string $role): string
    {
        $this->addMember($this->company, $this->makeUser("{$role}@meghna.example"), $role);

        return $this->login("{$role}@meghna.example");
    }

    public function test_a_new_company_has_the_default_units_listed_by_name(): void
    {
        $r = $this->req('GET', '/api/v1/units?per_page=100')->assertOk();
        $names = array_column($r->json('data'), 'name');
        $this->assertEqualsCanonicalizing(array_keys(CompanyProvisioner::UNITS), $names);
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);
        $this->assertSame(['id', 'name'], array_keys($r->json('data.0')));
        $this->assertSame(3, DB::table('units')->where('company_id', $this->company->id)->where('name', 'kg')->value('decimals'));

        $this->req('GET', '/api/v1/units?q=li')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'litre');
        $this->req('GET', '/api/v1/units?sort=-name&per_page=2')->assertJsonPath('data.0.name', 'pcs')->assertJsonPath('meta.last_page', 5);
        $this->assertProblem($this->req('GET', '/api/v1/units?sort=decimals'), 422, 'validation_failed');
        $this->assertProblem($this->req('GET', '/api/v1/units?status=all'), 422, 'validation_failed');
    }

    public function test_add_a_unit_inline_audits_it_and_keeps_names_unique_per_company(): void
    {
        $r = $this->req('POST', '/api/v1/units', ['name' => '  ream ', 'decimals' => 0])->assertCreated();
        $r->assertJsonPath('data.name', 'ream');
        $id = $r->json('data.id');
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'units')->where('auditable_id', $id)->exists());
        $this->req('POST', '/api/v1/units', ['name' => 'Gram', 'decimals' => 3])->assertCreated();
        $this->assertSame(3, DB::table('units')->where('company_id', $this->company->id)->where('name', 'Gram')->value('decimals'));

        $dup = $this->assertProblem($this->req('POST', '/api/v1/units', ['name' => 'KG']), 422, 'validation_failed');
        $this->assertSame(['This unit already exists'], $dup->json('errors.name'));

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->api('POST', '/api/v1/units', $this->login('padma@agro.example'), $other->id, ['name' => 'ream'])->assertCreated();
    }

    public function test_unit_validation(): void
    {
        $this->assertSame(['Enter a unit name'], $this->assertProblem($this->req('POST', '/api/v1/units', ['name' => ' ']), 422, 'validation_failed')->json('errors.name'));
        $this->assertProblem($this->req('POST', '/api/v1/units', ['name' => str_repeat('x', 31)]), 422, 'validation_failed');
        $this->assertSame(['Quantities allow at most 3 decimals'],
            $this->assertProblem($this->req('POST', '/api/v1/units', ['name' => 'tola', 'decimals' => 4]), 422, 'validation_failed')->json('errors.decimals'));
        $this->assertProblem($this->req('POST', '/api/v1/units', ['name' => 'tola', 'decimals' => -1]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/units', ['name' => 'tola', 'symbol' => 't']), 422, 'validation_failed');
    }

    public function test_unit_permissions_and_company_isolation(): void
    {
        // inventory.view lists, inventory.create adds: Store keeper may add, Viewer and Sales staff may not.
        foreach (['viewer', 'sales'] as $role) {
            $t = $this->as($role);
            $this->req('GET', '/api/v1/units', token: $t)->assertOk();
            $this->assertProblem($this->req('POST', '/api/v1/units', ['name' => "by {$role}"], $t), 403, 'permission_denied');
        }
        $this->req('POST', '/api/v1/units', ['name' => 'sack'], $this->as('store'))->assertCreated();

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->api('POST', '/api/v1/units', $this->login('padma@agro.example'), $other->id, ['name' => 'maund'])->assertCreated();
        $names = array_column($this->req('GET', '/api/v1/units?per_page=100')->json('data'), 'name');
        $this->assertContains('sack', $names);
        $this->assertNotContains('maund', $names);
    }

    public function test_tax_rules_are_read_only_for_every_member_and_scoped_to_the_company(): void
    {
        $this->req('GET', '/api/v1/tax-rules')->assertOk()->assertJsonPath('meta.total', 0);
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->seed(DemoTaxRuleSeeder::class);

        $store = $this->as('store'); // no settings or accounting permission: still reads the rules for its forms
        $r = $this->req('GET', '/api/v1/tax-rules', token: $store)->assertOk()->assertJsonPath('meta.total', 4);
        $vat = collect($r->json('data'))->firstWhere('name', 'VAT 15%');
        $this->assertSame('15.0000', $vat['rate']);
        $this->assertSame('sales', $vat['applies_to']);
        $this->assertSame('Tax payable', $vat['ledger_name']);
        $this->assertTrue($vat['is_active']);
        $this->assertSame(1, $vat['version']);
        $this->assertSame(8, DB::table('tax_rules')->count(), 'four rules per company');
        $ids = array_column($r->json('data'), 'id');
        $this->assertSame(0, DB::table('tax_rules')->whereIn('id', $ids)->where('company_id', $other->id)->count());

        $this->assertProblem($this->req('GET', '/api/v1/tax-rules?applies_to=sales'), 422, 'validation_failed');
        $this->seed(DemoTaxRuleSeeder::class); // safe to run again
        $this->assertSame(8, DB::table('tax_rules')->count());
    }

    public function test_the_defaults_command_adds_missing_units_to_existing_companies(): void
    {
        DB::table('units')->where('company_id', $this->company->id)->whereIn('name', ['jar', 'dozen'])->delete();
        $this->artisan('pluto:company:defaults', ['company_id' => $this->company->id])
            ->expectsOutputToContain('2 unit(s) added')->assertSuccessful();
        $this->assertSame(count(CompanyProvisioner::UNITS), DB::table('units')->where('company_id', $this->company->id)->count());
        $this->artisan('pluto:company:defaults')->expectsOutputToContain('0 unit(s) added')->assertSuccessful();
    }
}
