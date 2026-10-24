<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** /stock-groups (D6, backlog P3.1-BE-03; GAP-20, BR-026): a reporting tree of any depth, no cycles, delete only when unused. */
class StockGroupTest extends TestCase
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

    private function group(string $name, ?int $parent = null): int
    {
        return $this->req('POST', '/api/v1/stock-groups', ['name' => $name, 'parent_id' => $parent])->assertCreated()->json('data.id');
    }

    public function test_tree_of_any_depth_listed_parent_first(): void
    {
        $r = $this->req('POST', '/api/v1/stock-groups', ['name' => 'Trading goods', 'sort_order' => 1])->assertCreated();
        $r->assertJsonPath('data.parent_id', null)->assertJsonPath('data.items_count', 0)->assertJsonMissingPath('data.code_prefix');
        $trading = $r->json('data.id');
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'stock_groups')->where('auditable_id', $trading)->exists());
        $food = $this->group('Food', $trading);
        $this->group('Oils', $food); // third level: allowed for stock groups
        $this->req('POST', '/api/v1/stock-groups', ['name' => 'Consumables', 'sort_order' => 2])->assertCreated();

        $this->assertSame(['Trading goods', 'Food', 'Oils', 'Consumables'], array_column($this->req('GET', '/api/v1/stock-groups')->json('data'), 'name'));
        $this->assertSame(['Consumables', 'Food', 'Oils', 'Trading goods'], array_column($this->req('GET', '/api/v1/stock-groups?sort=name')->json('data'), 'name'));
    }

    public function test_no_cycles_and_no_category_fields(): void
    {
        $a = $this->group('A');
        $b = $this->group('B', $a);
        $c = $this->group('C', $b);
        $this->assertSame(['A stock group cannot move under one of its own sub-stock groups'],
            $this->assertProblem($this->req('PATCH', "/api/v1/stock-groups/{$a}", ['parent_id' => $c]), 422, 'validation_failed')->json('errors.parent_id'));
        $this->assertSame(['A stock group cannot be its own parent'],
            $this->assertProblem($this->req('PATCH', "/api/v1/stock-groups/{$b}", ['parent_id' => $b]), 422, 'validation_failed')->json('errors.parent_id'));
        $this->req('PATCH', "/api/v1/stock-groups/{$c}", ['parent_id' => $a, 'name' => 'C2'])->assertOk()->assertJsonPath('data.parent_id', $a)->assertJsonPath('data.name', 'C2');

        $this->assertProblem($this->req('POST', '/api/v1/stock-groups', ['name' => 'D', 'code_prefix' => 'DD']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/stock-groups', ['name' => 'a']), 422, 'validation_failed'); // duplicate name
    }

    public function test_delete_only_when_unused_permissions_and_isolation(): void
    {
        $c = $this->company->id;
        $top = $this->group('Trading goods');
        $leaf = $this->group('Food', $top);
        DB::table('products')->insert(['company_id' => $c, 'item_type' => 'product', 'code' => 'P-1', 'name' => 'Rice', 'stock_group_id' => $leaf,
            'unit_id' => DB::table('units')->where('company_id', $c)->value('id'), 'selling_price' => '1.00',
            'income_ledger_id' => DB::table('ledgers')->where('company_id', $c)->where('code', '4101')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        $this->req('GET', '/api/v1/stock-groups')->assertJsonPath('data.1.items_count', 1);

        $this->assertProblem($this->req('DELETE', "/api/v1/stock-groups/{$leaf}"), 409, 'has_history');
        $this->assertProblem($this->req('DELETE', "/api/v1/stock-groups/{$top}"), 409, 'has_history');
        DB::table('products')->where('stock_group_id', $leaf)->delete();
        $this->req('DELETE', "/api/v1/stock-groups/{$leaf}")->assertNoContent();
        $this->assertTrue(AuditLog::where('action', 'deleted')->where('auditable_type', 'stock_groups')->where('auditable_id', $leaf)->exists());

        $this->addMember($this->company, $this->makeUser('sales@meghna.example'), 'sales');
        $sales = $this->login('sales@meghna.example');
        $this->req('GET', '/api/v1/stock-groups', token: $sales)->assertOk();
        $this->assertProblem($this->req('POST', '/api/v1/stock-groups', ['name' => 'X'], $sales), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->assertProblem($this->api('DELETE', "/api/v1/stock-groups/{$top}", $this->login('padma@agro.example'), $other->id), 404, 'not_found');
    }
}
