<?php

namespace Tests\Feature\Inventory;

use App\Domain\Inventory\StockService;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * GET /warehouses/{id}/stock (D8; backlog P11.1-BE-01; BR-052): the stock-tracked products with a balance or a reorder
 * setting in one warehouse, their status, search, below-reorder filter, sort and paging; values only for roles with
 * inventory.view_costs; another company's warehouse is 404.
 */
class WarehouseStockTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Warehouse $tej;

    private Product $oil;

    private Product $rice;

    private Product $ghee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->tej = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar');
        $this->rice = $this->product('Chinigura rice 25kg', 'bag', '2400.00');
        $this->ghee = $this->product('Pran ghee 1kg tin', 'pcs', '950.00');
        $this->product('Home delivery', 'pcs', '150.00', 'service');
        $this->product('Not stocked here', 'pcs', '10.00');
        $this->in($this->oil, '18', '15210.00');                 // 18 @ 845.00
        $this->in($this->rice, '4', '8000.00');                  // below its reorder level of 5
        $this->setting($this->rice, '5', '20');
        $this->setting($this->ghee, '10', '24');                 // set up but never received
    }

    private function in(Product $p, string $qty, string $value): void
    {
        $this->useBooks();
        DB::transaction(fn () => app(StockService::class)->stockIn($p, $this->tej, Quantity::of($qty), Money::of($value), $this->tej, '2026-07-05'));
    }

    private function setting(Product $p, string $level, string $qty): void
    {
        DB::table('product_warehouse_settings')->insert(['company_id' => $this->books->id, 'product_id' => $p->id, 'warehouse_id' => $this->tej->id,
            'reorder_level' => $level, 'reorder_qty' => $qty, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stock(string $query = '', ?string $token = null)
    {
        return $this->api('GET', "/api/v1/warehouses/{$this->tej->id}/stock{$query}", $token ?? $this->token, $this->books->id);
    }

    public function test_lists_the_products_stocked_in_the_warehouse_with_their_status(): void
    {
        $r = $this->stock()->assertOk()->assertJsonPath('meta.total', 3);
        $this->assertSame(['Chinigura rice 25kg', 'Pran ghee 1kg tin', 'Soybean oil 5L jar'], array_column($r->json('data'), 'product_name'));
        $this->assertSame(['low_stock', 'out_of_stock', 'in_stock'], array_column($r->json('data'), 'status'));
        $r->assertJsonPath('data.2.qty_on_hand', '18.000')->assertJsonPath('data.2.value', '15210.00')->assertJsonPath('data.2.warehouse_name', 'Tejgaon warehouse')
            ->assertJsonPath('data.0.reorder_level', '5.000')->assertJsonPath('data.0.reorder_qty', '20.000')->assertJsonPath('data.1.value', '0.00');
    }

    public function test_search_filter_sort_and_paging(): void
    {
        $this->assertSame(['Soybean oil 5L jar'], array_column($this->stock('?q=soybean')->assertOk()->json('data'), 'product_name'));
        $this->assertSame(['Chinigura rice 25kg', 'Pran ghee 1kg tin'], array_column($this->stock('?below_reorder=true')->json('data'), 'product_name'));
        $this->assertSame(['18.000', '4.000', '0.000'], array_column($this->stock('?sort=-qty_on_hand')->json('data'), 'qty_on_hand'));
        $this->stock('?per_page=2&page=2')->assertOk()->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.product_name', 'Soybean oil 5L jar');
        $this->assertProblem($this->stock('?sort=price'), 422, 'validation_failed');
        $this->assertProblem($this->stock('?colour=red'), 422, 'validation_failed');
    }

    public function test_values_are_hidden_without_view_costs_and_other_companies_get_404(): void
    {
        $this->userWithRole('store', 'store@meghna.example');
        $row = $this->stock('', $this->login('store@meghna.example'))->assertOk()->json('data.2');
        $this->assertSame('18.000', $row['qty_on_hand']);
        $this->assertArrayNotHasKey('value', $row);

        $other = $this->makeCompany('Padma Traders Ltd.', $this->makeUser('other@padma.example'));
        $this->assertProblem($this->api('GET', "/api/v1/warehouses/{$this->tej->id}/stock", $this->login('other@padma.example'), $other->id), 404, 'not_found');
    }
}
