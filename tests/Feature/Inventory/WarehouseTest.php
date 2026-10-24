<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * /warehouses (D7/D8, backlog P3.1-BE-04; 06 §3, CF-10, GAP-02): each warehouse gets exactly one inventory ledger
 * under 1301 (the seeded 1301-01 for the first one, a new 1301-NN after that, or a shared existing one).
 */
class WarehouseTest extends TestCase
{
    private Company $company;

    private string $token;

    private int $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->token = $this->login('owner@meghna.example');
        $this->branch = DB::table('branches')->where('company_id', $this->company->id)->value('id');
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        return $this->api($method, $uri, $token ?? $this->token, $this->company->id, $data, $headers);
    }

    private function create(string $code, string $name, array $extra = [])
    {
        return $this->req('POST', '/api/v1/warehouses', ['code' => $code, 'name' => $name, 'branch_id' => $this->branch] + $extra,
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function inventoryLedgers(): array
    {
        return DB::table('ledgers')->where('company_id', $this->company->id)->where('ledger_kind', 'inventory')->orderBy('code')->pluck('name', 'code')->all();
    }

    public function test_each_warehouse_gets_exactly_one_inventory_ledger_under_1301(): void
    {
        $this->assertSame(['1301-01' => 'Inventory, main warehouse'], $this->inventoryLedgers());

        $tej = $this->create('wh-tej', ' Tejgaon warehouse ', ['address' => 'Tejgaon I/A, Dhaka', 'phone' => '01711000111', 'opens_on' => '2026-07-01'])->assertCreated();
        $tej->assertJsonPath('data.code', 'WH-TEJ')->assertJsonPath('data.name', 'Tejgaon warehouse')->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.opens_on', '2026-07-01')->assertJsonPath('data.products_count', 0)->assertJsonPath('data.below_reorder_count', 0)
            ->assertJsonPath('data.stock_value', '0.00')->assertJsonPath('data.version', 1)->assertHeader('ETag', 'W/"1"');
        $this->assertSame(['1301-01' => 'Inventory, Tejgaon warehouse'], $this->inventoryLedgers(), 'the first warehouse takes the seeded 1301-01');

        $ngj = $this->create('WH-NGJ', 'Narayanganj depot')->assertCreated();
        $ctg = $this->create('WH-CTG', 'Chattogram store')->assertCreated();
        $this->assertSame(['1301-01' => 'Inventory, Tejgaon warehouse', '1301-02' => 'Inventory, Narayanganj depot', '1301-03' => 'Inventory, Chattogram store'],
            $this->inventoryLedgers());
        $ledgers = DB::table('warehouses')->where('company_id', $this->company->id)->pluck('inventory_ledger_id');
        $this->assertSame(3, $ledgers->unique()->count(), 'one ledger per warehouse');
        $this->assertSame(DB::table('ledgers')->where('code', '1301-02')->where('company_id', $this->company->id)->value('id'), $ngj->json('data.inventory_ledger_id'));
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'warehouses')->where('auditable_id', $ctg->json('data.id'))->exists());

        // Sharing an existing inventory ledger creates none.
        $this->create('WH-NGJ2', 'Narayanganj annex', ['inventory_ledger_id' => $ngj->json('data.inventory_ledger_id')])->assertCreated()
            ->assertJsonPath('data.inventory_ledger_id', $ngj->json('data.inventory_ledger_id'));
        $this->assertCount(3, $this->inventoryLedgers());

        $list = $this->req('GET', '/api/v1/warehouses')->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertSame(['Chattogram store', 'Narayanganj annex', 'Narayanganj depot', 'Tejgaon warehouse'], array_column($list->json('data'), 'name'));
        $this->req('GET', '/api/v1/warehouses/'.$tej->json('data.id'))->assertOk()->assertJsonPath('data.code', 'WH-TEJ')->assertHeader('ETag', 'W/"1"');
    }

    public function test_validation(): void
    {
        $this->create('WH-1', 'Main')->assertCreated();
        $this->assertSame(['Another warehouse already uses this code'], $this->assertProblem($this->create('wh-1', 'Again'), 422, 'validation_failed')->json('errors.code'));
        $r = $this->assertProblem($this->req('POST', '/api/v1/warehouses', ['name' => ' '], ['Idempotency-Key' => (string) Str::uuid()]), 422, 'validation_failed');
        $this->assertSame(['Enter a warehouse code'], $r->json('errors.code'));
        $this->assertSame(['Enter the warehouse name'], $r->json('errors.name'));
        $this->assertSame(['Choose the branch this warehouse belongs to'], $r->json('errors.branch_id'));
        $this->assertProblem($this->create('WH 2', 'Spaces'), 422, 'validation_failed');
        $this->assertProblem($this->create('WH-2', 'Bad status', ['status' => 'closed']), 422, 'validation_failed');
        $this->assertProblem($this->create('WH-2', 'Bad date', ['opens_on' => '01/07/2026']), 422, 'validation_failed');
        $this->assertProblem($this->create('WH-2', 'Unknown', ['capacity_used_pct' => '50']), 422, 'validation_failed');

        // The inventory ledger must be an active inventory ledger under 1301.
        $cash = DB::table('ledgers')->where('company_id', $this->company->id)->where('code', '1101-01')->value('id');
        $this->assertSame(['Choose an active inventory ledger under 1301 Inventory, or leave it empty to create one.'],
            $this->assertProblem($this->create('WH-2', 'Cash?', ['inventory_ledger_id' => $cash]), 422, 'validation_failed')->json('errors.inventory_ledger_id'));
        // The manager must be a member.
        $stranger = $this->makeUser('stranger@elsewhere.example');
        $this->assertProblem($this->create('WH-2', 'Managed', ['manager_user_id' => $stranger->id]), 422, 'validation_failed');
        $this->assertSame(1, DB::table('warehouses')->where('company_id', $this->company->id)->count());
    }

    public function test_edit_with_if_match_renames_its_ledger_and_keeps_one_default_for_sales(): void
    {
        $a = $this->create('WH-A', 'Tejgaon warehouse', ['is_default_for_sales' => true])->assertCreated()->json('data');
        $b = $this->create('WH-B', 'Narayanganj depot')->assertCreated()->json('data');
        $manager = $this->makeUser('store@meghna.example');
        $this->addMember($this->company, $manager, 'store');

        $this->assertProblem($this->req('PATCH', "/api/v1/warehouses/{$b['id']}", ['name' => 'X']), 428, 'if_match_required');
        $r = $this->req('PATCH', "/api/v1/warehouses/{$b['id']}", ['name' => 'Narayanganj hub', 'is_default_for_sales' => true, 'manager_user_id' => $manager->id],
            ['If-Match' => 'W/"1"'])->assertOk();
        $r->assertJsonPath('data.version', 2)->assertJsonPath('data.manager.name', $manager->name)->assertHeader('ETag', 'W/"2"');
        $this->assertSame('Inventory, Narayanganj hub', DB::table('ledgers')->where('id', $b['inventory_ledger_id'])->value('name'));
        $this->req('GET', "/api/v1/warehouses/{$a['id']}")->assertJsonPath('data.is_default_for_sales', false);
        $this->assertSame(1, DB::table('warehouses')->where('company_id', $this->company->id)->where('is_default_for_sales', true)->count());
        $this->assertTrue(AuditLog::where('action', 'updated')->where('auditable_type', 'warehouses')->where('auditable_id', $b['id'])->exists());

        $this->assertProblem($this->req('PATCH', "/api/v1/warehouses/{$b['id']}", ['name' => 'Stale'], ['If-Match' => 'W/"1"']), 409, 'version_conflict')
            ->assertJsonPath('current_version', 2);
        $this->assertProblem($this->req('PATCH', "/api/v1/warehouses/{$b['id']}", ['code' => 'WH-A'], ['If-Match' => 'W/"2"']), 422, 'validation_failed');
        $this->req('PATCH', "/api/v1/warehouses/{$b['id']}", ['code' => 'WH-B'], ['If-Match' => 'W/"2"'])->assertOk(); // its own code
    }

    public function test_cannot_make_inactive_while_it_holds_stock(): void
    {
        $w = $this->create('WH-A', 'Tejgaon warehouse')->json('data');
        $c = $this->company->id;
        $product = DB::table('products')->insertGetId(['company_id' => $c, 'item_type' => 'product', 'code' => 'P-1', 'name' => 'Soybean oil 5L',
            'unit_id' => DB::table('units')->where('company_id', $c)->where('name', 'jar')->value('id'), 'selling_price' => '900.00',
            'income_ledger_id' => DB::table('ledgers')->where('company_id', $c)->where('code', '4101')->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_balances')->insert(['company_id' => $c, 'product_id' => $product, 'warehouse_id' => $w['id'], 'qty_on_hand' => '18.000',
            'total_value' => '15210.00', 'avg_unit_cost' => '845.0000', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_warehouse_settings')->insert(['company_id' => $c, 'product_id' => $product, 'warehouse_id' => $w['id'], 'reorder_level' => '20.000',
            'reorder_qty' => '50.000', 'created_at' => now(), 'updated_at' => now()]);

        $this->req('GET', "/api/v1/warehouses/{$w['id']}")->assertJsonPath('data.stock_value', '15210.00')
            ->assertJsonPath('data.products_count', 1)->assertJsonPath('data.below_reorder_count', 1);
        $this->assertSame(['This warehouse still holds stock; transfer or adjust it to zero before making it inactive.'],
            $this->assertProblem($this->req('PATCH', "/api/v1/warehouses/{$w['id']}", ['status' => 'inactive'], ['If-Match' => 'W/"1"']), 422, 'validation_failed')->json('errors.status'));
        $other = $this->create('WH-B', 'Other')->json('data');
        $this->assertProblem($this->req('PATCH', "/api/v1/warehouses/{$w['id']}", ['inventory_ledger_id' => $other['inventory_ledger_id']], ['If-Match' => 'W/"1"']), 422, 'validation_failed');

        DB::table('stock_balances')->update(['qty_on_hand' => 0, 'total_value' => 0]);
        $this->req('PATCH', "/api/v1/warehouses/{$w['id']}", ['status' => 'inactive'], ['If-Match' => 'W/"1"'])->assertOk()->assertJsonPath('data.status', 'inactive');
    }

    public function test_permissions_cost_masking_isolation_and_idempotency(): void
    {
        $key = (string) Str::uuid();
        $body = ['code' => 'WH-A', 'name' => 'Tejgaon warehouse', 'branch_id' => $this->branch];
        $first = $this->req('POST', '/api/v1/warehouses', $body, ['Idempotency-Key' => $key])->assertCreated();
        $this->req('POST', '/api/v1/warehouses', $body, ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, DB::table('warehouses')->where('company_id', $this->company->id)->count());

        $this->addMember($this->company, $this->makeUser('store@meghna.example'), 'store');
        $store = $this->login('store@meghna.example');
        $this->req('GET', '/api/v1/warehouses', token: $store)->assertOk()->assertJsonMissingPath('data.0.stock_value'); // BR-052
        $this->req('POST', '/api/v1/warehouses', ['code' => 'WH-S', 'name' => 'Store keeper adds', 'branch_id' => $this->branch], ['Idempotency-Key' => (string) Str::uuid()], $store)->assertCreated();

        $this->addMember($this->company, $this->makeUser('viewer@meghna.example'), 'viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->assertProblem($this->req('POST', '/api/v1/warehouses', ['code' => 'WH-V', 'name' => 'V', 'branch_id' => $this->branch], ['Idempotency-Key' => (string) Str::uuid()], $viewer), 403, 'permission_denied');
        $this->assertProblem($this->req('PATCH', '/api/v1/warehouses/'.$first->json('data.id'), ['name' => 'V'], ['If-Match' => 'W/"1"'], $viewer), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->api('GET', '/api/v1/warehouses', $ot, $other->id)->assertJsonPath('meta.total', 0);
        $this->assertProblem($this->api('GET', '/api/v1/warehouses/'.$first->json('data.id'), $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('POST', '/api/v1/warehouses', $ot, $other->id, $body, ['Idempotency-Key' => (string) Str::uuid()]), 422, 'validation_failed'); // our branch
        $this->assertProblem($this->req('GET', '/api/v1/warehouses?status=active'), 422, 'validation_failed');
    }
}
