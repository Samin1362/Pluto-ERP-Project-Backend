<?php

namespace Tests\Feature\Reports;

use App\Domain\Inventory\WarehouseService;
use App\Models\Party;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Inventory reports: /reports/inventory/valuation|low-stock|summary (D12–D14; 06 §11.1, §17; BR-052; TC-INV-007; P11.1-BE-04). */
class InventoryReportTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $tej;

    private Warehouse $ngj;

    private Warehouse $annex;

    private Product $oil;

    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->tej = $this->warehouse();
        $this->ngj = $this->warehouse('WH-NGJ', 'Narayanganj depot');
        $this->useBooks();
        $this->annex = app(WarehouseService::class)->create(['code' => 'WH-TJA', 'name' => 'Tejgaon annex', 'inventory_ledger_id' => $this->tej->inventory_ledger_id,
            'branch_id' => DB::table('branches')->where('company_id', $this->books->id)->value('id')]);
        $this->oil = $this->product('Soybean oil 5L jar');
        $this->rice = $this->product('Chinigura rice 25kg', 'bag', '2400.00');
        DB::table('products')->where('id', $this->oil->id)->update(['preferred_supplier_id' => $this->supplier->id]);
    }

    private function day(int $ago = 0): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->subDays($ago)->toDateString();
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function bill(Warehouse $w, Product $p, string $qty, string $cost, string $date): void
    {
        $b = $this->req('POST', '/api/v1/purchase-bills', ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'B-'.Str::random(5), 'bill_date' => $date,
            'warehouse_id' => $w->id, 'lines' => [['product_id' => $p->id, 'quantity' => $qty, 'unit_cost' => $cost]]])->assertCreated();
        $this->req('POST', '/api/v1/purchase-bills/'.$b->json('data.id').'/post')->assertOk();
    }

    private function setting(Product $p, Warehouse $w, string $level, string $qty = '0'): void
    {
        DB::table('product_warehouse_settings')->insert(['company_id' => $this->books->id, 'product_id' => $p->id, 'warehouse_id' => $w->id,
            'reorder_level' => $level, 'reorder_qty' => $qty, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_tc_inv_007_valuation_as_of_a_date_agrees_with_the_inventory_ledgers(): void
    {
        $this->bill($this->tej, $this->oil, '100', '845.00', $this->day(2));
        $this->bill($this->ngj, $this->rice, '10', '2000.00', $this->day(2));
        $this->bill($this->tej, $this->oil, '50', '860.00', $this->day());          // after the earlier as-of date
        // a dispatched, not yet received transfer to the annex (same ledger) is still in 1301-01
        $t = $this->req('POST', '/api/v1/stock-transfers', ['from_warehouse_id' => $this->tej->id, 'to_warehouse_id' => $this->annex->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '10']]])->json('data.id');
        $this->req('POST', "/api/v1/stock-transfers/{$t}/dispatch")->assertOk();

        $then = $this->req('GET', '/api/v1/reports/inventory/valuation?as_of='.$this->day(1))->assertOk();
        $this->assertSame([['Chinigura rice 25kg', '10.000', '2000.0000', '20000.00'], ['Soybean oil 5L jar', '100.000', '845.0000', '84500.00']],
            array_map(fn ($r) => [$r['name'], $r['quantity'], $r['avg_cost'], $r['value']], $then->json('data.rows')));
        $then->assertJsonPath('data.totals.value', '104500.00')->assertJsonPath('data.checks.0.ok', true);

        $now = $this->req('GET', '/api/v1/reports/inventory/valuation')->assertOk();
        $now->assertJsonPath('data.totals.value', '139000.00')->assertJsonPath('data.checks.0.ok', true); // 147,500 − 10 jars in transit at the 850.00 average
        $this->assertStringContainsString('on its way', $now->json('data.checks.0.message'));
        $tb = (string) DB::table('journal_lines as jl')->join('ledgers as l', 'l.id', '=', 'jl.ledger_id')->where('l.company_id', $this->books->id)
            ->where('l.ledger_kind', 'inventory')->selectRaw('SUM(jl.debit) - SUM(jl.credit) as b')->value('b');
        $this->assertSame('147500.00', $tb, 'TB inventory = valuation + same-ledger transit');

        $this->req('GET', '/api/v1/reports/inventory/valuation?level=groups')->assertOk()->assertJsonPath('data.rows.0.name', 'No stock group');
        $this->req('GET', "/api/v1/reports/inventory/valuation?warehouse_id={$this->ngj->id}")->assertOk()->assertJsonPath('data.totals.value', '20000.00')
            ->assertJsonPath('data.checks', []);
        $this->req('GET', '/api/v1/reports/inventory/valuation?format=csv')->assertOk();
        $this->assertProblem($this->req('GET', '/api/v1/reports/inventory/valuation?level=x'), 422, 'validation_failed');
    }

    public function test_low_stock_suggests_what_to_order(): void
    {
        $this->bill($this->tej, $this->oil, '4', '845.00', $this->day());
        $this->setting($this->oil, $this->tej, '10', '24');        // low: 4 ≤ 10, order the reorder qty
        $this->setting($this->rice, $this->ngj, '5');               // out: nothing on hand, back to the level
        $this->setting($this->oil, $this->ngj, '0', '10');          // no level: not listed

        $r = $this->req('GET', '/api/v1/reports/inventory/low-stock')->assertOk();
        $this->assertSame([['Chinigura rice 25kg', 'Narayanganj depot', 'out_of_stock', '0.000', '5.000', null], ['Soybean oil 5L jar', 'Tejgaon warehouse', 'low_stock', '4.000', '24.000', 'Bengal Edible Oils']],
            array_map(fn ($x) => [$x['name'], $x['warehouse'], $x['status'], $x['on_hand'], $x['suggested'], $x['supplier']], $r->json('data.rows')));
        $r->assertJsonPath('data.rows.1.order_value', '20280.00')->assertJsonPath('data.totals.items', 2)->assertJsonPath('data.totals.out_of_stock', 1);
        $this->assertSame(['Soybean oil 5L jar'], array_column($this->req('GET', '/api/v1/reports/inventory/low-stock?status=low')->json('data.rows'), 'name'));
        $grouped = $this->req('GET', '/api/v1/reports/inventory/low-stock?group_by_supplier=true')->json('data.rows');
        $this->assertSame(['group', 'item', 'group', 'item'], array_column($grouped, 'kind'));
        $this->assertSame('Bengal Edible Oils', $grouped[0]['name']);

        $this->userWithRole('store', 'store@meghna.example');
        $row = $this->req('GET', '/api/v1/reports/inventory/low-stock', [], $this->login('store@meghna.example'))->assertOk()->json('data.rows.1');
        $this->assertArrayNotHasKey('order_value', $row);
        $this->assertProblem($this->req('GET', '/api/v1/reports/inventory/valuation', [], $this->login('store@meghna.example')), 403, 'permission_denied');
    }

    public function test_summary(): void
    {
        $this->bill($this->tej, $this->oil, '100', '845.00', $this->day());
        $this->setting($this->rice, $this->ngj, '5');
        $customer = $this->customer();
        $inv = $this->req('POST', '/api/v1/sales-invoices', ['party_id' => $customer->id, 'invoice_date' => $this->day(), 'warehouse_id' => $this->tej->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '30', 'unit_price' => '950.00']]])->assertCreated();
        $this->req('POST', '/api/v1/sales-invoices/'.$inv->json('data.id').'/post', [])->assertOk();

        $r = $this->req('GET', '/api/v1/reports/inventory/summary')->assertOk();
        $r->assertJsonPath('data.totals.products', 2)->assertJsonPath('data.totals.in_stock', 1)->assertJsonPath('data.totals.out_of_stock', 1)
            ->assertJsonPath('data.totals.stock_value', '59150.00')->assertJsonPath('data.rows.0.name', 'Soybean oil 5L jar')->assertJsonPath('data.rows.0.sold', '30.000')
            ->assertJsonPath('data.rows.0.on_hand', '70.000')->assertJsonPath('data.rows.0.days_of_cover', 70)->assertJsonPath('data.rows.0.revenue', '28500.00')
            ->assertJsonPath('data.meta.attention.0.name', 'Chinigura rice 25kg');
        $this->assertCount(6, $r->json('data.meta.monthly'));
        $monthly = $r->json('data.meta.monthly');
        $this->assertSame('100.000', end($monthly)['units_in']);
        $this->assertProblem($this->req('GET', '/api/v1/reports/inventory/summary?x=1'), 422, 'validation_failed');

        $this->userWithRole('store', 'store@meghna.example');
        $this->assertArrayNotHasKey('stock_value', $this->req('GET', '/api/v1/reports/inventory/summary', [], $this->login('store@meghna.example'))->json('data.totals'));
    }
}
