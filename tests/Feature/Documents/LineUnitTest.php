<?php

namespace Tests\Feature\Documents;

use App\Domain\Inventory\ProductService;
use App\Models\Party;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Trade document lines in an item's extra unit (P10.7-BE-01, PEP-464; BR-069): purchase orders, bills, quotations and
 * invoices take an optional unit_id, store the chosen unit with its factor, refuse a unit the item does not have, and
 * keep the unit when a quotation or an order is converted. Stock in base units is P10.7-BE-02.
 */
class LineUnitTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Party $customer;

    private Warehouse $wh;

    private Product $soap;

    private array $units;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->customer = $this->customer();
        $this->wh = $this->warehouse();
        $c = $this->books->id;
        $this->units = DB::table('units')->where('company_id', $c)->pluck('id', 'name')->all();
        $this->soap = app(ProductService::class)->create(['item_type' => 'product', 'name' => 'Lux soap 100g', 'selling_price' => '55.00',
            'unit_id' => $this->units['pcs'], 'income_ledger_id' => DB::table('ledgers')->where('company_id', $c)->where('code', '4101')->value('id'),
            'units' => [['unit_id' => $this->units['box'], 'factor' => '12']]]);
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function req(string $method, string $uri, array $data = [])
    {
        $headers = $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : [];
        $r = $this->api($method, $uri, $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function bill(array $lines): array
    {
        return ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'UN-'.Str::random(6), 'bill_date' => $this->today(), 'warehouse_id' => $this->wh->id, 'lines' => $lines];
    }

    private function invoice(array $lines): array
    {
        return ['party_id' => $this->customer->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id, 'lines' => $lines];
    }

    public function test_a_bill_line_in_boxes_stores_the_unit_and_factor(): void
    {
        $r = $this->req('POST', '/api/v1/purchase-bills', $this->bill([
            ['product_id' => $this->soap->id, 'unit_id' => $this->units['box'], 'quantity' => '5', 'unit_cost' => '500.00'],
            ['product_id' => $this->soap->id, 'quantity' => '6', 'unit_cost' => '42.00']]))->assertCreated();

        $lines = $r->json('data.lines');
        $this->assertSame(['box', 'pcs'], array_column($lines, 'unit'));
        $this->assertSame([$this->units['box'], $this->units['pcs']], array_column($lines, 'unit_id'), 'the base unit is stored too');
        $this->assertSame(['12.000000', '1.000000'], array_column($lines, 'unit_factor'));
        $this->assertSame('2500.00', $lines[0]['line_total'], 'amounts stay quantity x price per chosen unit');
        $row = DB::table('purchase_bill_lines')->where('purchase_bill_id', $r->json('data.id'))->where('line_no', 1)->first();
        $this->assertSame(['box', $this->units['box'], '12.000000'], [$row->unit_name, (int) $row->unit_id, $row->unit_factor]);
    }

    public function test_a_unit_the_item_does_not_have_is_refused_on_every_document(): void
    {
        $line = ['product_id' => $this->soap->id, 'unit_id' => $this->units['carton'], 'quantity' => '1'];
        $calls = [
            ['/api/v1/purchase-bills', $this->bill([$line + ['unit_cost' => '1']])],
            ['/api/v1/purchase-bills/calculate', $this->bill([$line + ['unit_cost' => '1']])],
            ['/api/v1/purchase-orders', ['party_id' => $this->supplier->id, 'order_date' => $this->today(), 'warehouse_id' => $this->wh->id, 'lines' => [$line + ['unit_cost' => '1']]]],
            ['/api/v1/sales-invoices', $this->invoice([$line])],
            ['/api/v1/sales-invoices/calculate', $this->invoice([$line])],
            ['/api/v1/quotations', ['party_id' => $this->customer->id, 'quotation_date' => $this->today(), 'valid_until' => $this->today(), 'lines' => [$line]]],
        ];
        foreach ($calls as [$uri, $body]) {
            $r = $this->assertProblem($this->req('POST', $uri, $body), 422, 'validation_failed');
            $this->assertSame(['Lux soap 100g is bought and sold in pcs, box only'], $r->json('errors')['lines.0.unit_id'] ?? null, $uri);
        }
        $this->assertSame(0, DB::table('purchase_bills')->count() + DB::table('sales_invoices')->count() + DB::table('quotations')->count() + DB::table('purchase_orders')->count());
    }

    public function test_an_invoice_line_in_boxes_takes_the_box_price_when_none_is_typed(): void
    {
        $calc = $this->req('POST', '/api/v1/sales-invoices/calculate', $this->invoice([['product_id' => $this->soap->id, 'unit_id' => $this->units['box'], 'quantity' => '2']]))->assertOk();
        $this->assertSame('1320.00', $calc->json('data.lines.0.line_total'), '55.00 x 12 = 660.00 a box');

        $inv = $this->req('POST', '/api/v1/sales-invoices', $this->invoice([['product_id' => $this->soap->id, 'unit_id' => $this->units['box'], 'quantity' => '2']]))->assertCreated();
        $inv->assertJsonPath('data.lines.0.unit', 'box')->assertJsonPath('data.lines.0.unit_price', '660.00')->assertJsonPath('data.lines.0.unit_factor', '12.000000');
    }

    public function test_converting_a_quotation_or_an_order_keeps_the_unit(): void
    {
        $qt = $this->req('POST', '/api/v1/quotations', ['party_id' => $this->customer->id, 'quotation_date' => $this->today(), 'valid_until' => $this->today(),
            'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->soap->id, 'unit_id' => $this->units['box'], 'quantity' => '3', 'unit_price' => '650.00']]])->assertCreated();
        $qt->assertJsonPath('data.lines.0.unit', 'box')->assertJsonPath('data.lines.0.unit_id', $this->units['box']);
        $this->req('POST', '/api/v1/quotations/'.$qt->json('data.id').'/status', ['status' => 'accepted'])->assertOk();
        $inv = $this->req('POST', '/api/v1/quotations/'.$qt->json('data.id').'/convert')->assertCreated();
        $inv->assertJsonPath('data.lines.0.unit', 'box')->assertJsonPath('data.lines.0.unit_factor', '12.000000')->assertJsonPath('data.lines.0.unit_price', '650.00');

        $po = $this->req('POST', '/api/v1/purchase-orders', ['party_id' => $this->supplier->id, 'order_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->soap->id, 'unit_id' => $this->units['box'], 'quantity' => '10', 'unit_cost' => '480.00']]])->assertCreated();
        $po->assertJsonPath('data.lines.0.unit', 'box');
        $bill = $this->req('POST', '/api/v1/purchase-orders/'.$po->json('data.id').'/convert')->assertCreated();
        $bill->assertJsonPath('data.lines.0.unit', 'box')->assertJsonPath('data.lines.0.unit_id', $this->units['box'])->assertJsonPath('data.lines.0.quantity', '10.000');
    }
}
