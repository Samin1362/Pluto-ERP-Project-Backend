<?php

namespace Tests\Feature\Parties;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /party-groups ("Add group…" in B2/C2, backlog P2.1-BE-02) and POST /parties/bulk-deactivate
 * (B1/C1 "Mark inactive", P2.1-BE-03; BR-026, ERR-5: per-item results).
 */
class PartyGroupAndBulkTest extends TestCase
{
    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        return $this->api($method, $uri, $token ?? $this->token, $this->company->id, $data, $headers);
    }

    private function as(string $role): string
    {
        $this->addMember($this->company, $this->makeUser("{$role}@meghna.example"), $role);

        return $this->login("{$role}@meghna.example");
    }

    private function party(string $type, string $name, string $mobile): int
    {
        return $this->req('POST', '/api/v1/parties', ['party_type' => $type, 'name' => $name, 'mobile' => $mobile])->assertCreated()->json('data.id');
    }

    public function test_create_group_returns_it_audits_it_and_lists_it(): void
    {
        $r = $this->req('POST', '/api/v1/party-groups', ['party_type' => 'supplier', 'name' => '  Edible oils  '])->assertCreated();
        $r->assertJsonPath('data.party_type', 'supplier')->assertJsonPath('data.name', 'Edible oils');
        $id = $r->json('data.id');
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'party_groups')->where('auditable_id', $id)->exists());

        $this->req('GET', '/api/v1/party-groups?type=supplier')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->req('GET', '/api/v1/party-groups?type=customer')->assertJsonPath('meta.total', 0);

        // The new group can be chosen on a supplier, not on a customer.
        $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Bengal Edible Oils', 'mobile' => '01911222333', 'party_group_id' => $id])
            ->assertCreated()->assertJsonPath('data.group.name', 'Edible oils');
        $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Rahman', 'mobile' => '01711458230', 'party_group_id' => $id]), 422, 'validation_failed');
    }

    public function test_group_names_are_unique_per_company_and_party_type(): void
    {
        $this->req('POST', '/api/v1/party-groups', ['party_type' => 'customer', 'name' => 'Wholesale'])->assertCreated();
        $this->assertProblem($this->req('POST', '/api/v1/party-groups', ['party_type' => 'customer', 'name' => 'wholesale']), 422, 'validation_failed')
            ->assertJsonPath('errors.name.0', 'A customer group with this name already exists');
        $this->req('POST', '/api/v1/party-groups', ['party_type' => 'supplier', 'name' => 'Wholesale'])->assertCreated();

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->api('POST', '/api/v1/party-groups', $this->login('padma@agro.example'), $other->id, ['party_type' => 'customer', 'name' => 'Wholesale'])->assertCreated();
        $this->assertSame(1, DB::table('party_groups')->where('company_id', $this->company->id)->where('party_type', 'customer')->count());
    }

    public function test_group_validation_permission_and_idempotency(): void
    {
        $r = $this->assertProblem($this->req('POST', '/api/v1/party-groups', ['name' => ' ']), 422, 'validation_failed');
        $this->assertSame(['Enter a group name'], $r->json('errors.name'));
        $this->assertNotNull($r->json('errors.party_type'));
        $this->assertProblem($this->req('POST', '/api/v1/party-groups', ['party_type' => 'customer', 'name' => str_repeat('x', 81)]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/party-groups', ['party_type' => 'customer', 'name' => 'X', 'colour' => 'red']), 422, 'validation_failed');

        $this->assertProblem($this->req('POST', '/api/v1/party-groups', ['party_type' => 'customer', 'name' => 'Retail'], token: $this->as('viewer')), 403, 'permission_denied');
        $this->req('POST', '/api/v1/party-groups', ['party_type' => 'customer', 'name' => 'Retail'], token: $this->as('sales'))->assertCreated();

        $key = (string) Str::uuid();
        $a = $this->req('POST', '/api/v1/party-groups', ['party_type' => 'supplier', 'name' => 'Packaging'], ['Idempotency-Key' => $key])->assertCreated();
        $b = $this->req('POST', '/api/v1/party-groups', ['party_type' => 'supplier', 'name' => 'Packaging'], ['Idempotency-Key' => $key])->assertCreated();
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame('true', $b->headers->get('Idempotency-Replayed'));
    }

    public function test_bulk_deactivate_reports_each_id_and_audits_each_party(): void
    {
        $a = $this->party('customer', 'Rahman Stores', '01711458230');
        $b = $this->party('customer', 'Karim Traders', '01812000111');
        $s = $this->party('supplier', 'Bengal Edible Oils', '01911222333');
        $this->req('POST', "/api/v1/parties/{$b}/deactivate")->assertOk();

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $foreign = $this->api('POST', '/api/v1/parties', $this->login('padma@agro.example'), $other->id,
            ['party_type' => 'customer', 'name' => 'Padma Customer', 'mobile' => '01711000999'])->assertCreated()->json('data.id');
        $deleted = $this->party('customer', 'Gone Ltd.', '01711000888');
        $this->req('DELETE', "/api/v1/parties/{$deleted}")->assertNoContent();

        $r = $this->req('POST', '/api/v1/parties/bulk-deactivate', ['ids' => [$a, $b, $s, $foreign, $deleted, 999999]])->assertOk();
        $this->assertSame([$a, $b, $s], $r->json('data.succeeded'), 'an already inactive party also ends inactive');
        $this->assertSame([$foreign, $deleted, 999999], array_column($r->json('data.failed'), 'id'));
        $this->assertSame(['not_found'], array_values(array_unique(array_column($r->json('data.failed'), 'code'))));
        $this->assertSame('Not found (or it belongs to another company)', $r->json('data.failed.0.detail'));

        $this->assertSame(['inactive'], DB::table('parties')->whereIn('id', [$a, $b, $s])->distinct()->pluck('status')->all());
        $this->assertSame('active', DB::table('parties')->where('id', $foreign)->value('status'), 'another company is never touched');
        $this->assertSame([$a, $s], AuditLog::where('action', 'deactivated')->where('auditable_type', 'parties')
            ->whereIn('auditable_id', [$a, $s])->orderBy('auditable_id')->pluck('auditable_id')->all(), 'one audit row per changed party');
    }

    public function test_bulk_deactivate_validates_the_body_and_needs_edit_permission(): void
    {
        $a = $this->party('customer', 'Rahman Stores', '01711458230');
        foreach ([[], ['ids' => []], ['ids' => 'x'], ['ids' => [$a, $a]], ['ids' => [0]], ['ids' => range(1, 101)], ['ids' => [$a], 'extra' => 1]] as $body) {
            $this->assertProblem($this->req('POST', '/api/v1/parties/bulk-deactivate', $body), 422, 'validation_failed');
        }
        $this->assertProblem($this->req('POST', '/api/v1/parties/bulk-deactivate', ['ids' => [$a]], token: $this->as('sales')), 403, 'permission_denied');
        $this->assertProblem($this->req('POST', '/api/v1/parties/bulk-deactivate', ['ids' => [$a]], token: $this->as('viewer')), 403, 'permission_denied');
        $this->req('POST', '/api/v1/parties/bulk-deactivate', ['ids' => [$a]], token: $this->as('accountant'))->assertOk()->assertJsonPath('data.succeeded', [$a]);
        $this->assertSame('inactive', DB::table('parties')->where('id', $a)->value('status'), 'deactivated by the accountant');
    }
}
