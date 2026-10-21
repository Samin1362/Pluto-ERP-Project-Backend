<?php

namespace Tests\Feature\Platform;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** P1.1-BE-02: GET /audit-logs — activity of one record for detail-page timelines (05 getAuditLogs, 07 §6.2). */
class AuditLogTest extends TestCase
{
    private Company $company;

    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->addMember($this->company, $this->makeUser('viewer@meghna.example'), 'viewer');
        $this->addMember($this->company, $this->makeUser('store@meghna.example'), 'store');
        $this->owner = $this->login('owner@meghna.example');
    }

    private function customer(): int
    {
        $c = $this->company->id;
        $id = $this->api('POST', '/api/v1/parties', $this->owner, $c, ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711458230'])
            ->assertCreated()->json('data.id');
        $this->api('PATCH', "/api/v1/parties/{$id}", $this->owner, $c, ['name' => 'Rahman Stores Ltd'], ['If-Match' => 'W/"1"'])->assertOk();
        $this->api('POST', "/api/v1/parties/{$id}/deactivate", $this->owner, $c)->assertOk();

        return $id;
    }

    public function test_customer_create_edit_deactivate_show_up_newest_first_for_a_viewer(): void
    {
        $id = $this->customer();
        $viewer = $this->login('viewer@meghna.example');

        $r = $this->api('GET', "/api/v1/audit-logs?auditable_type=party&auditable_id={$id}", $viewer, $this->company->id)->assertOk();
        $this->assertSame(['deactivated', 'updated', 'created'], array_column($r->json('data'), 'action'));
        $r->assertJsonPath('data.0.user.name', 'Owner')->assertJsonPath('data.0.old_values.status', 'active')
            ->assertJsonPath('data.0.new_values.status', 'inactive')->assertJsonPath('data.1.new_values.name', 'Rahman Stores Ltd')
            ->assertJsonPath('data.2.new_values.code', 'CUS-0001')->assertJsonPath('meta.total', 3);
        $this->assertNotEmpty($r->json('data.0.created_at'));
    }

    public function test_the_records_module_permission_is_required(): void
    {
        $id = $this->customer();
        $store = $this->login('store@meghna.example');   // Store keeper has no parties.view

        $this->assertProblem($this->api('GET', "/api/v1/audit-logs?auditable_type=party&auditable_id={$id}", $store, $this->company->id), 403, 'permission_denied');
    }

    public function test_records_of_another_company_or_unknown_records_are_not_found(): void
    {
        $other = $this->makeCompany('Padma Traders Ltd.', $this->makeUser('owner@padma.example'));
        $padma = $this->login('owner@padma.example');
        $theirs = $this->api('POST', '/api/v1/parties', $padma, $other->id, ['party_type' => 'customer', 'name' => 'Padma Retail', 'mobile' => '01811458230'])
            ->assertCreated()->json('data.id');

        $this->assertProblem($this->api('GET', "/api/v1/audit-logs?auditable_type=party&auditable_id={$theirs}", $this->owner, $this->company->id), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/api/v1/audit-logs?auditable_type=party&auditable_id=999999', $this->owner, $this->company->id), 404, 'not_found');
    }

    public function test_type_and_id_are_required_and_validated(): void
    {
        $c = $this->company->id;
        $this->assertProblem($this->api('GET', '/api/v1/audit-logs', $this->owner, $c), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/audit-logs?auditable_type=spaceship&auditable_id=1', $this->owner, $c), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/audit-logs?auditable_type=party&auditable_id=abc', $this->owner, $c), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/audit-logs?auditable_type=party&auditable_id=1&sort=x', $this->owner, $c), 422, 'validation_failed');
    }

    public function test_secret_values_never_leave_the_api(): void
    {
        $id = $this->customer();
        DB::table('audit_logs')->insert(['company_id' => $this->company->id, 'action' => 'updated', 'auditable_type' => 'parties',
            'auditable_id' => $id, 'old_values' => json_encode(['password_hash' => 'x', 'name' => 'A']),
            'new_values' => json_encode(['api_token' => 'y', 'nested' => ['two_factor_secret' => 'z', 'ok' => 1]]), 'created_at' => now()->addMinute()]);

        $r = $this->api('GET', "/api/v1/audit-logs?auditable_type=party&auditable_id={$id}", $this->owner, $this->company->id)->assertOk();
        $this->assertSame(['name' => 'A'], $r->json('data.0.old_values'));
        $this->assertSame(['nested' => ['ok' => 1]], $r->json('data.0.new_values'));
    }
}
