<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** GET /products and DELETE /products/{id} (D1, backlog P3.1-BE-06; FR-030, BR-026, BR-052, TC-INV-006). */
class ProductListTest extends TestCase
{
    private Company $company;

    private string $token;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $this->token = $this->login('owner@meghna.example');
        $c = $this->company->id;
        $branch = DB::table('branches')->where('company_id', $c)->value('id');
        foreach (['tej' => ['WH-TEJ', 'Tejgaon warehouse'], 'ngj' => ['WH-NGJ', 'Narayanganj depot']] as $k => [$code, $name]) {
            $this->ids[$k] = $this->req('POST', '/api/v1/warehouses', ['code' => $code, 'name' => $name, 'branch_id' => $branch])->json('data.id');
        }
        $this->ids['oils'] = $this->req('POST', '/api/v1/product-categories', ['name' => 'Edible oils', 'code_prefix' => 'OIL'])->json('data.id');
        $this->ids['soy'] = $this->req('POST', '/api/v1/product-categories', ['name' => 'Soybean oil', 'parent_id' => $this->ids['oils']])->json('data.id');
        $this->ids['rice'] = $this->req('POST', '/api/v1/product-categories', ['name' => 'Rice', 'code_prefix' => 'RIC'])->json('data.id');

        // In stock (Tejgaon 100, reorder 20), low (Narayanganj 5 ≤ reorder 10), out of stock, a service and an inactive item.
        $this->ids['soy5'] = $this->product('Soybean oil 5L', '920.00', $this->ids['soy'], ['barcode' => '8941100500012']);
        $this->ids['mustard'] = $this->product('Mustard oil 1L', '260.00', $this->ids['oils']);
        $this->ids['rice50'] = $this->product('Miniket rice 50kg', '3400.00', $this->ids['rice']);
        $this->ids['delivery'] = $this->req('POST', '/api/v1/products', ['item_type' => 'service', 'name' => 'Delivery charge', 'unit_id' => $this->unit('pcs'),
            'selling_price' => '500.00', 'income_ledger_id' => $this->ledger('4201')])->json('data.id');
        $this->ids['old'] = $this->product('Old palm oil', '100.00', $this->ids['oils'], ['is_active' => false]);
        $this->stock($this->ids['soy5'], 'tej', '100.000', '84500.00', '20.000');
        $this->stock($this->ids['mustard'], 'ngj', '5.000', '1150.00', '10.000');
        DB::table('product_warehouse_settings')->insert(['company_id' => $c, 'product_id' => $this->ids['rice50'], 'warehouse_id' => $this->ids['ngj'],
            'reorder_level' => '5.000', 'reorder_qty' => '10.000', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        if ($method === 'POST') {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }

        return $this->api($method, $uri, $token ?? $this->token, $this->company->id, $data, $headers);
    }

    private function unit(string $name): int
    {
        return DB::table('units')->where('company_id', $this->company->id)->where('name', $name)->value('id');
    }

    private function ledger(string $code): int
    {
        return DB::table('ledgers')->where('company_id', $this->company->id)->where('code', $code)->value('id');
    }

    private function product(string $name, string $price, int $category, array $extra = []): int
    {
        return $this->req('POST', '/api/v1/products', $extra + ['item_type' => 'product', 'name' => $name, 'unit_id' => $this->unit('jar'), 'selling_price' => $price,
            'income_ledger_id' => $this->ledger('4101'), 'category_id' => $category])->assertCreated()->json('data.id');
    }

    private function stock(int $product, string $wh, string $qty, string $value, string $reorder): void
    {
        $c = $this->company->id;
        DB::table('stock_balances')->insert(['company_id' => $c, 'product_id' => $product, 'warehouse_id' => $this->ids[$wh], 'qty_on_hand' => $qty,
            'total_value' => $value, 'avg_unit_cost' => '0', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('product_warehouse_settings')->insert(['company_id' => $c, 'product_id' => $product, 'warehouse_id' => $this->ids[$wh],
            'reorder_level' => $reorder, 'reorder_qty' => '0', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function names(string $query = ''): array
    {
        return array_column($this->req('GET', '/api/v1/products'.$query)->assertOk()->json('data'), 'name');
    }

    public function test_list_with_stock_figures_and_status(): void
    {
        $r = $this->req('GET', '/api/v1/products')->assertOk()->assertJsonPath('meta.total', 4);
        $this->assertSame(['Delivery charge', 'Miniket rice 50kg', 'Mustard oil 1L', 'Soybean oil 5L'], array_column($r->json('data'), 'name'), 'active items by name');
        $rows = collect($r->json('data'))->keyBy('name');
        $this->assertSame(['100.000', '84500.00', '845.0000', 'in_stock'],
            [$rows['Soybean oil 5L']['on_hand'], $rows['Soybean oil 5L']['stock_value'], $rows['Soybean oil 5L']['avg_cost'], $rows['Soybean oil 5L']['stock_status']]);
        $this->assertSame(['5.000', 'low_stock', '230.0000'], [$rows['Mustard oil 1L']['on_hand'], $rows['Mustard oil 1L']['stock_status'], $rows['Mustard oil 1L']['avg_cost']]);
        $this->assertSame(['0.000', 'out_of_stock'], [$rows['Miniket rice 50kg']['on_hand'], $rows['Miniket rice 50kg']['stock_status']]);
        $this->assertSame([null, 'not_tracked'], [$rows['Delivery charge']['on_hand'], $rows['Delivery charge']['stock_status']]);
        $this->assertSame('Soybean oil', $rows['Soybean oil 5L']['category']['name']);
        $this->assertArrayNotHasKey('warehouses', $rows['Soybean oil 5L'], 'per-warehouse rows only on the detail');
    }

    public function test_filters_search_and_sort(): void
    {
        $this->assertSame(['Delivery charge'], $this->names('?item_type=service'));
        $this->assertSame(['Mustard oil 1L', 'Soybean oil 5L'], $this->names("?category_id={$this->ids['oils']}"), 'a category includes its sub-categories');
        $this->assertSame(['Soybean oil 5L'], $this->names("?category_id={$this->ids['soy']}"));
        $this->assertSame(['Soybean oil 5L'], $this->names('?stock_status=in_stock'));
        $this->assertSame(['Mustard oil 1L'], $this->names('?stock_status=low_stock'));
        $this->assertSame(['Miniket rice 50kg'], $this->names('?stock_status=out_of_stock'));
        $this->assertSame(['Old palm oil'], $this->names('?active=false'));
        $this->assertCount(5, $this->names('?active=all'));
        $this->assertSame(['Soybean oil 5L'], $this->names('?q=89411005'), 'barcode');
        $this->assertSame(['Mustard oil 1L'], $this->names('?q=OIL-0002'), 'code');
        $this->assertSame(['Mustard oil 1L', 'Soybean oil 5L'], $this->names('?q=oil'));

        // A warehouse narrows to the products stocked or set up there, with that warehouse's figures.
        $ngj = collect($this->req('GET', "/api/v1/products?warehouse_id={$this->ids['ngj']}")->json('data'))->keyBy('name');
        $this->assertSame(['Miniket rice 50kg', 'Mustard oil 1L'], $ngj->keys()->all());
        $this->assertSame(['0.000', 'out_of_stock'], [$ngj['Miniket rice 50kg']['on_hand'], $ngj['Miniket rice 50kg']['stock_status']]);
        $tej = collect($this->req('GET', "/api/v1/products?warehouse_id={$this->ids['tej']}")->json('data'))->keyBy('name');
        $this->assertSame(['Soybean oil 5L'], $tej->keys()->all());

        $this->assertSame(['Soybean oil 5L', 'Mustard oil 1L', 'Miniket rice 50kg', 'Delivery charge'], $this->names('?sort=-name'));
        $this->assertSame(['Mustard oil 1L', 'Delivery charge', 'Soybean oil 5L', 'Miniket rice 50kg'], $this->names('?sort=selling_price'));
        $this->assertSame('Soybean oil 5L', $this->names('?sort=-on_hand')[0]);
        $this->req('GET', '/api/v1/products?per_page=3&page=2')->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.current_page', 2)->assertJsonCount(1, 'data');

        foreach (['?sort=cost', '?stock_status=low', '?active=yes', '?item_type=bundle', '?category_id=999999', '?colour=red'] as $bad) {
            $this->assertProblem($this->req('GET', '/api/v1/products'.$bad), 422, 'validation_failed');
        }
    }

    public function test_tc_auth_010_list_has_no_cost_fields_for_a_store_keeper(): void
    {
        $this->addMember($this->company, $this->makeUser('store@meghna.example'), 'store');
        $rows = $this->req('GET', '/api/v1/products', token: $this->login('store@meghna.example'))->assertOk()->json('data');
        foreach ($rows as $row) {
            foreach (['avg_cost', 'stock_value', 'last_purchase_cost'] as $field) {
                $this->assertArrayNotHasKey($field, $row);
            }
        }
        $this->assertSame('100.000', collect($rows)->firstWhere('name', 'Soybean oil 5L')['on_hand']);
    }

    public function test_tc_inv_006_delete_only_unused_items_otherwise_deactivate(): void
    {
        $this->req('DELETE', "/api/v1/products/{$this->ids['rice50']}")->assertNoContent();
        $this->assertSoftDeleted('products', ['id' => $this->ids['rice50']]);
        $this->assertSame(0, DB::table('product_warehouse_settings')->where('product_id', $this->ids['rice50'])->count());
        $this->assertTrue(AuditLog::where('action', 'deleted')->where('auditable_type', 'products')->where('auditable_id', $this->ids['rice50'])->exists());
        $this->assertProblem($this->req('GET', "/api/v1/products/{$this->ids['rice50']}"), 404, 'not_found');
        $this->assertNotContains('Miniket rice 50kg', $this->names('?active=all'));
        // Its code is never reused.
        $this->req('POST', '/api/v1/products', ['item_type' => 'product', 'name' => 'Chinigura rice', 'unit_id' => $this->unit('kg'), 'selling_price' => '150.00',
            'income_ledger_id' => $this->ledger('4101'), 'category_id' => $this->ids['rice']])->assertJsonPath('data.code', 'RIC-0002');

        DB::table('stock_movements')->insert(['company_id' => $this->company->id, 'product_id' => $this->ids['soy5'], 'warehouse_id' => $this->ids['tej'],
            'moved_on' => now()->toDateString(), 'movement_type' => 'purchase_in', 'quantity_in' => '100.000', 'unit_cost' => '845.0000', 'value_in' => '84500.00',
            'qty_balance_after' => '100.000', 'avg_cost_after' => '845.0000', 'source_type' => 'purchase_bill', 'source_id' => 1, 'created_at' => now()]);
        $this->assertSame('This item is used on stock or trade documents; make it inactive instead.',
            $this->assertProblem($this->req('DELETE', "/api/v1/products/{$this->ids['soy5']}"), 409, 'has_history')->json('detail'));
        $this->req('PATCH', "/api/v1/products/{$this->ids['soy5']}", ['is_active' => false], ['If-Match' => 'W/"1"'])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertContains('Soybean oil 5L', $this->names('?active=false'));
    }

    public function test_permissions_and_isolation(): void
    {
        $this->addMember($this->company, $this->makeUser('store@meghna.example'), 'store');
        $store = $this->login('store@meghna.example');
        $this->req('GET', '/api/v1/products', token: $store)->assertOk();
        $this->assertProblem($this->req('DELETE', "/api/v1/products/{$this->ids['rice50']}", token: $store), 403, 'permission_denied'); // no inventory.delete

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->api('GET', '/api/v1/products', $ot, $other->id)->assertJsonPath('meta.total', 0);
        $this->assertProblem($this->api('DELETE', "/api/v1/products/{$this->ids['rice50']}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('GET', "/api/v1/products?warehouse_id={$this->ids['tej']}", $ot, $other->id), 422, 'validation_failed');
    }
}
