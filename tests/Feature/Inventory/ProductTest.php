<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POST /products, GET/PATCH /products/{id} (D2–D4, backlog P3.1-BE-05; BR-026, BR-052, TC-INV-005, TC-AUTH-010 part).
 * Quantities stay 0 until purchase bills post (P4); stock rows are inserted directly where a test needs them.
 */
class ProductTest extends TestCase
{
    private Company $company;

    private string $token;

    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->token = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $ledger = fn (string $code) => DB::table('ledgers')->where('company_id', $c)->where('code', $code)->value('id');
        $branch = DB::table('branches')->where('company_id', $c)->value('id');
        $wh = fn (string $code, string $name) => $this->req('POST', '/api/v1/warehouses', ['code' => $code, 'name' => $name, 'branch_id' => $branch])->assertCreated()->json('data.id');
        $this->ids = [
            'jar' => DB::table('units')->where('company_id', $c)->where('name', 'jar')->value('id'),
            'pcs' => DB::table('units')->where('company_id', $c)->where('name', 'pcs')->value('id'),
            'sales' => $ledger('4101'), 'delivery' => $ledger('4201'), 'purchases' => $ledger('5101'), 'cash' => $ledger('1101-01'),
            'tej' => $wh('WH-TEJ', 'Tejgaon warehouse'), 'ngj' => $wh('WH-NGJ', 'Narayanganj depot'),
        ];
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        if ($method === 'POST') {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }

        return $this->api($method, $uri, $token ?? $this->token, $this->company->id, $data, $headers);
    }

    private function oil(array $extra = []): array
    {
        return $extra + ['item_type' => 'product', 'name' => 'Soybean oil 5L jar', 'unit_id' => $this->ids['jar'], 'selling_price' => '920.00',
            'income_ledger_id' => $this->ids['sales']];
    }

    private function as(string $role): string
    {
        $this->addMember($this->company, $this->makeUser("{$role}@meghna.example"), $role);

        return $this->login("{$role}@meghna.example");
    }

    public function test_create_a_product_with_warehouse_settings_and_code_from_the_category_prefix(): void
    {
        $cat = $this->req('POST', '/api/v1/product-categories', ['name' => 'Edible oils', 'code_prefix' => 'OIL'])->json('data.id');
        $group = $this->req('POST', '/api/v1/stock-groups', ['name' => 'Trading goods'])->json('data.id');
        $supplier = $this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'Bengal Edible Oils', 'mobile' => '01911222333'])->json('data.id');

        $r = $this->req('POST', '/api/v1/products', $this->oil(['category_id' => $cat, 'stock_group_id' => $group, 'barcode' => '8941100500012',
            'wholesale_price' => '900.00', 'last_purchase_cost' => '845.5000', 'preferred_supplier_id' => $supplier,
            'warehouses' => [['warehouse_id' => $this->ids['tej'], 'reorder_level' => '20', 'reorder_qty' => '50', 'opening_qty' => '0']]]))->assertCreated();
        $r->assertHeader('ETag', 'W/"1"')->assertJsonPath('data.code', 'OIL-0001')->assertJsonPath('data.item_type', 'product')
            ->assertJsonPath('data.category.name', 'Edible oils')->assertJsonPath('data.stock_group.name', 'Trading goods')->assertJsonPath('data.unit', 'jar')
            ->assertJsonPath('data.preferred_supplier.code', 'SUP-0001')->assertJsonPath('data.selling_price', '920.00')
            ->assertJsonPath('data.track_stock', true)->assertJsonPath('data.on_hand', '0.000')->assertJsonPath('data.stock_status', 'out_of_stock')
            ->assertJsonPath('data.avg_cost', '0.0000')->assertJsonPath('data.stock_value', '0.00')->assertJsonPath('data.last_purchase_cost', '845.5000');
        $levels = collect($r->json('data.warehouses'))->keyBy('warehouse_name');
        $this->assertSame(['Narayanganj depot', 'Tejgaon warehouse'], $levels->keys()->all(), 'every active warehouse');
        $this->assertSame(['20.000', '50.000', 'out_of_stock'], [$levels['Tejgaon warehouse']['reorder_level'], $levels['Tejgaon warehouse']['reorder_qty'], $levels['Tejgaon warehouse']['status']]);
        $this->assertSame('not_stocked', $levels['Narayanganj depot']['status']);
        $id = $r->json('data.id');
        $this->assertTrue(AuditLog::where('action', 'created')->where('auditable_type', 'products')->where('auditable_id', $id)->exists());
        $this->assertSame(0, DB::table('stock_balances')->count(), 'no stock is created');

        // Codes: the series continues; a user-entered code is kept (upper-cased); without a prefix the series is PRD.
        $this->req('POST', '/api/v1/products', $this->oil(['name' => 'Mustard oil 1L', 'category_id' => $cat]))->assertJsonPath('data.code', 'OIL-0002');
        $this->req('POST', '/api/v1/products', $this->oil(['name' => 'Rice 50kg', 'code' => 'rice-50']))->assertJsonPath('data.code', 'RICE-50');
        $this->req('POST', '/api/v1/products', $this->oil(['name' => 'Sugar']))->assertJsonPath('data.code', 'PRD-0001');
        $this->req('GET', "/api/v1/products/{$id}")->assertOk()->assertJsonPath('data.code', 'OIL-0001')->assertHeader('ETag', 'W/"1"');
    }

    public function test_a_service_tracks_no_stock(): void
    {
        $r = $this->req('POST', '/api/v1/products', ['item_type' => 'service', 'name' => 'Delivery charge', 'unit_id' => $this->ids['pcs'],
            'selling_price' => '500.00', 'income_ledger_id' => $this->ids['delivery'], 'expense_ledger_id' => $this->ids['purchases']])->assertCreated();
        $r->assertJsonPath('data.track_stock', false)->assertJsonPath('data.stock_status', 'not_tracked')->assertJsonPath('data.on_hand', null)
            ->assertJsonPath('data.avg_cost', null)->assertJsonPath('data.stock_value', null)->assertJsonPath('data.warehouses', []);
        $this->assertSame(['Services have no stock, so they have no warehouse settings'], $this->assertProblem($this->req('POST', '/api/v1/products',
            ['item_type' => 'service', 'name' => 'Loading', 'unit_id' => $this->ids['pcs'], 'selling_price' => '1', 'income_ledger_id' => $this->ids['sales'],
                'warehouses' => [['warehouse_id' => $this->ids['tej'], 'reorder_level' => '5']]]), 422, 'validation_failed')->json('errors.warehouses'));
    }

    public function test_validation(): void
    {
        $r = $this->assertProblem($this->req('POST', '/api/v1/products', ['item_type' => 'product', 'name' => ' ']), 422, 'validation_failed');
        $this->assertSame(['Enter the item name'], $r->json('errors.name'));
        $this->assertSame(['Choose a unit'], $r->json('errors.unit_id'));
        $this->assertSame(['Enter the selling price'], $r->json('errors.selling_price'));
        $this->assertSame(['Choose the income account'], $r->json('errors.income_ledger_id'));

        $this->req('POST', '/api/v1/products', $this->oil(['code' => 'OIL-1', 'barcode' => '111']))->assertCreated();
        $this->assertSame(['Another item already uses this code'], $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['code' => 'oil-1'])), 422, 'validation_failed')->json('errors.code'));
        $this->assertSame(['Another item already uses this barcode'], $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['barcode' => '111'])), 422, 'validation_failed')->json('errors.barcode'));
        $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['selling_price' => '-1.00'])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['selling_price' => '9.999'])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['item_type' => 'bundle'])), 422, 'validation_failed');
        $this->assertSame(['Choose an active income account'],
            $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['income_ledger_id' => $this->ids['cash']])), 422, 'validation_failed')->json('errors.income_ledger_id'));
        $this->assertSame(['Choose an active expense account'],
            $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['expense_ledger_id' => $this->ids['sales']])), 422, 'validation_failed')->json('errors.expense_ledger_id'));
        $this->assertSame(['Opening stock can be entered once stock posting is live (phase 4); use 0 for now'], $this->assertProblem($this->req('POST', '/api/v1/products',
            $this->oil(['warehouses' => [['warehouse_id' => $this->ids['tej'], 'opening_qty' => '18', 'opening_unit_cost' => '845']]])), 422, 'validation_failed')->json('errors')['warehouses.0.opening_qty']);
        $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['warehouses' => [['warehouse_id' => $this->ids['tej']], ['warehouse_id' => $this->ids['tej']]]])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['warehouses' => [['warehouse_id' => $this->ids['tej'], 'bin' => 'A1']]])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['on_hand' => '10'])), 422, 'validation_failed');
        $this->assertSame(1, DB::table('products')->where('company_id', $this->company->id)->count());
    }

    public function test_tc_inv_005_patch_never_changes_quantity_or_cost_and_needs_if_match(): void
    {
        $p = $this->req('POST', '/api/v1/products', $this->oil(['warehouses' => [['warehouse_id' => $this->ids['tej'], 'reorder_level' => '20']]]))->json('data');
        DB::table('stock_balances')->insert(['company_id' => $this->company->id, 'product_id' => $p['id'], 'warehouse_id' => $this->ids['tej'],
            'qty_on_hand' => '18.000', 'total_value' => '15210.00', 'avg_unit_cost' => '845.0000', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertProblem($this->req('PATCH', "/api/v1/products/{$p['id']}", ['selling_price' => '950.00']), 428, 'if_match_required');
        foreach (['on_hand' => '100', 'avg_cost' => '1.0000', 'qty_on_hand' => '100'] as $field => $value) {
            $this->assertProblem($this->req('PATCH', "/api/v1/products/{$p['id']}", [$field => $value], ['If-Match' => 'W/"1"']), 422, 'validation_failed');
        }
        $this->assertProblem($this->req('PATCH', "/api/v1/products/{$p['id']}", ['warehouses' => [['warehouse_id' => $this->ids['tej'], 'opening_qty' => '5']]], ['If-Match' => 'W/"1"']), 422, 'validation_failed');

        $r = $this->req('PATCH', "/api/v1/products/{$p['id']}", ['selling_price' => '950.00', 'name' => 'Soybean oil 5L',
            'warehouses' => [['warehouse_id' => $this->ids['ngj'], 'reorder_level' => '10', 'reorder_qty' => '30']]], ['If-Match' => 'W/"1"'])->assertOk();
        $r->assertJsonPath('data.version', 2)->assertJsonPath('data.selling_price', '950.00')->assertJsonPath('data.on_hand', '18.000')
            ->assertJsonPath('data.avg_cost', '845.0000')->assertJsonPath('data.stock_value', '15210.00')->assertJsonPath('data.stock_status', 'low_stock')->assertHeader('ETag', 'W/"2"');
        $levels = collect($r->json('data.warehouses'))->keyBy('warehouse_name');
        $this->assertSame('20.000', $levels['Tejgaon warehouse']['reorder_level'], 'warehouses not sent keep their settings');
        $this->assertSame('10.000', $levels['Narayanganj depot']['reorder_level']);
        $this->assertSame('18.000', DB::table('stock_balances')->where('product_id', $p['id'])->value('qty_on_hand'));
        $this->assertTrue(AuditLog::where('action', 'updated')->where('auditable_type', 'products')->where('auditable_id', $p['id'])->exists());

        $this->assertProblem($this->req('PATCH', "/api/v1/products/{$p['id']}", ['name' => 'Stale'], ['If-Match' => 'W/"1"']), 409, 'version_conflict')->assertJsonPath('current_version', 2);
    }

    public function test_kind_is_fixed_once_the_product_is_used(): void
    {
        $p = $this->req('POST', '/api/v1/products', $this->oil(['warehouses' => [['warehouse_id' => $this->ids['tej'], 'reorder_level' => '5']]]))->json('data');
        $this->req('PATCH', "/api/v1/products/{$p['id']}", ['item_type' => 'service'], ['If-Match' => 'W/"1"'])->assertOk()
            ->assertJsonPath('data.track_stock', false)->assertJsonPath('data.warehouses', []);
        $this->assertSame(0, DB::table('product_warehouse_settings')->where('product_id', $p['id'])->count());
        $this->req('PATCH', "/api/v1/products/{$p['id']}", ['item_type' => 'product'], ['If-Match' => 'W/"2"'])->assertOk()->assertJsonPath('data.track_stock', true);

        DB::table('stock_movements')->insert(['company_id' => $this->company->id, 'product_id' => $p['id'], 'warehouse_id' => $this->ids['tej'],
            'moved_on' => now()->toDateString(), 'movement_type' => 'adjustment_in', 'quantity_in' => '1.000', 'quantity_out' => '0.000',
            'unit_cost' => '845.0000', 'value_in' => '845.00', 'qty_balance_after' => '1.000', 'avg_cost_after' => '845.0000',
            'source_type' => 'stock_adjustment', 'source_id' => 1, 'created_at' => now()]);
        $this->assertSame(['This item is already used on documents, so it cannot change between product and service.'],
            $this->assertProblem($this->req('PATCH', "/api/v1/products/{$p['id']}", ['item_type' => 'service'], ['If-Match' => 'W/"3"']), 422, 'validation_failed')->json('errors.item_type'));
    }

    public function test_tc_auth_010_store_keeper_gets_no_cost_fields(): void
    {
        $p = $this->req('POST', '/api/v1/products', $this->oil(['last_purchase_cost' => '845.0000']))->json('data');
        $store = $this->as('store');
        $r = $this->req('GET', "/api/v1/products/{$p['id']}", token: $store)->assertOk();
        foreach (['avg_cost', 'stock_value', 'last_purchase_cost'] as $field) {
            $this->assertArrayNotHasKey($field, $r->json('data'));
        }
        $this->assertArrayNotHasKey('value', $r->json('data.warehouses.0'));
        $this->assertSame('0.000', $r->json('data.on_hand'));
        // Store keeper may create and edit (inventory.create/edit) and still gets masked answers.
        $this->req('POST', '/api/v1/products', $this->oil(['name' => 'Rice 50kg']), token: $store)->assertCreated()->assertJsonMissingPath('data.avg_cost');
        $this->req('PATCH', "/api/v1/products/{$p['id']}", ['selling_price' => '930.00'], ['If-Match' => 'W/"1"'], $store)->assertOk()->assertJsonMissingPath('data.stock_value');
    }

    public function test_permissions_isolation_and_idempotency(): void
    {
        $key = (string) Str::uuid();
        $first = $this->req('POST', '/api/v1/products', $this->oil(), ['Idempotency-Key' => $key])->assertCreated();
        $this->req('POST', '/api/v1/products', $this->oil(), ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, DB::table('products')->where('company_id', $this->company->id)->count());

        foreach (['viewer', 'sales', 'accountant'] as $role) {
            $t = $this->as($role);
            $this->req('GET', '/api/v1/products/'.$first->json('data.id'), token: $t)->assertOk();
            $this->assertProblem($this->req('POST', '/api/v1/products', $this->oil(['name' => $role]), token: $t), 403, 'permission_denied');
            $this->assertProblem($this->req('PATCH', '/api/v1/products/'.$first->json('data.id'), ['name' => $role], ['If-Match' => 'W/"1"'], $t), 403, 'permission_denied');
        }

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', '/api/v1/products/'.$first->json('data.id'), $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('POST', '/api/v1/products', $ot, $other->id, $this->oil(), ['Idempotency-Key' => (string) Str::uuid()]), 422, 'validation_failed'); // our unit and ledger
    }
}
