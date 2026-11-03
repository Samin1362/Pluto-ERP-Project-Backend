<?php

namespace Tests\Feature\Documents;

use App\Domain\Inventory\ProductService;
use App\Models\Party;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Stock in base units for lines in an extra unit (P10.7-BE-02, PEP-465; BR-069): 1 box = 12 pcs. Posting, cancelling,
 * returns and the previews move quantity x factor in pieces; amounts stay quantity x price per box.
 */
class LineUnitPostingTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

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

    private function onHand(): array
    {
        $b = DB::table('stock_balances')->where('product_id', $this->soap->id)->where('warehouse_id', $this->wh->id)->first();

        return $b ? [$b->qty_on_hand, $b->total_value, $b->avg_unit_cost] : ['0.000', '0.00', '0.0000'];
    }

    /** Posts a bill of 5 boxes at 480.00 a box (= 40.00 a piece) and returns it. */
    private function postedBill(): array
    {
        $bill = $this->req('POST', '/api/v1/purchase-bills', ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'U-'.Str::random(6), 'bill_date' => $this->today(),
            'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->soap->id, 'unit_id' => $this->units['box'], 'quantity' => '5', 'unit_cost' => '480.00']]])
            ->assertCreated()->json('data');
        $this->req('POST', "/api/v1/purchase-bills/{$bill['id']}/post")->assertOk();

        return $bill;
    }

    private function postedInvoice(string $boxes = '2'): array
    {
        $inv = $this->req('POST', '/api/v1/sales-invoices', ['party_id' => $this->customer->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->soap->id, 'unit_id' => $this->units['box'], 'quantity' => $boxes]]])->assertCreated()->json('data');
        $this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/post")->assertOk();

        return $inv;
    }

    public function test_a_bill_in_boxes_receives_pieces_and_costs_them_per_piece(): void
    {
        $calc = $this->req('POST', '/api/v1/purchase-bills/calculate', ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'X', 'bill_date' => $this->today(),
            'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->soap->id, 'unit_id' => $this->units['box'], 'quantity' => '5', 'unit_cost' => '480.00']]])->assertOk();
        $this->assertSame('Stock in: 60.000 pcs (5.000 box) of Lux soap 100g into Tejgaon warehouse; average cost becomes 40.0000', $calc->json('data.effects.0'));

        $bill = $this->postedBill();

        $this->assertSame(['60.000', '2400.00', '40.0000'], $this->onHand(), '5 boxes = 60 pcs at 40.00');
        $m = DB::table('stock_movements')->where('source_id', $bill['id'])->where('movement_type', 'purchase_in')->first();
        $this->assertSame(['60.000', '40.0000', '2400.00'], [$m->quantity_in, $m->unit_cost, $m->value_in]);

        $this->req('POST', "/api/v1/purchase-bills/{$bill['id']}/cancel", ['reason' => 'entered_by_mistake'])->assertOk();
        $this->assertSame(['0.000', '0.00'], array_slice($this->onHand(), 0, 2), 'cancelling takes the 60 pcs back out');
    }

    public function test_an_invoice_in_boxes_issues_pieces_and_keeps_the_cost_per_box(): void
    {
        $this->postedBill();
        $calc = $this->req('POST', '/api/v1/sales-invoices/calculate', ['party_id' => $this->customer->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->soap->id, 'unit_id' => $this->units['box'], 'quantity' => '6']]])->assertOk();
        $this->assertSame('LOW_STOCK', collect($calc->json('data.warnings'))->firstWhere('line_no', 1)['code'] ?? null, '6 boxes = 72 pcs, only 60 on hand');
        $this->assertContains('Stock out: 72.000 pcs (6.000 box) of Lux soap 100g from Tejgaon warehouse', $calc->json('data.effects'));

        $inv = $this->postedInvoice('2');

        $this->assertSame(['36.000', '1440.00', '40.0000'], $this->onHand(), '60 - 24 pcs');
        $line = DB::table('sales_invoice_lines')->where('sales_invoice_id', $inv['id'])->first();
        $this->assertSame(['480.0000', '960.00', '660.00'], [$line->unit_cost, $line->cost_amount, $line->unit_price], 'cost and price per box');

        $this->req('POST', "/api/v1/sales-invoices/{$inv['id']}/cancel", ['reason' => 'customer_cancelled'])->assertOk();
        $this->assertSame(['60.000', '2400.00', '40.0000'], $this->onHand(), 'the 24 pcs come back at their cost');
    }

    public function test_returns_move_the_source_lines_unit_in_pieces(): void
    {
        $bill = $this->postedBill();
        $inv = $this->postedInvoice('2');
        $invLine = DB::table('sales_invoice_lines')->where('sales_invoice_id', $inv['id'])->value('id');

        $sr = $this->req('POST', '/api/v1/sales-returns', ['source_document_id' => $inv['id'], 'return_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'stock_action' => 'restock', 'lines' => [['source_line_id' => $invLine, 'quantity' => '1', 'reason' => 'damaged_in_transit']]])->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/sales-returns/{$sr}/post")->assertOk();
        $this->assertSame(['48.000', '1920.00'], array_slice($this->onHand(), 0, 2), '1 box back = 12 pcs at 480.00');
        $this->assertProblem($this->req('POST', '/api/v1/sales-returns', ['source_document_id' => $inv['id'], 'return_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'stock_action' => 'restock', 'lines' => [['source_line_id' => $invLine, 'quantity' => '2', 'reason' => 'leaking']]]), 422, 'return_qty_exceeds');
        $this->req('POST', "/api/v1/sales-returns/{$sr}/cancel", ['reason' => 'Counted wrong'])->assertOk();
        $this->assertSame('36.000', $this->onHand()[0]);

        $billLine = DB::table('purchase_bill_lines')->where('purchase_bill_id', $bill['id'])->value('id');
        $pr = $this->req('POST', '/api/v1/purchase-returns', ['source_document_id' => $bill['id'], 'return_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'stock_action' => 'send_back', 'lines' => [['source_line_id' => $billLine, 'quantity' => '1', 'reason' => 'torn_packs']]])->assertCreated();
        $pr->assertJsonPath('data.total', '480.00');
        $this->req('POST', '/api/v1/purchase-returns/'.$pr->json('data.id').'/post')->assertOk();
        $this->assertSame(['24.000', '960.00'], array_slice($this->onHand(), 0, 2), '1 box sent back = 12 pcs');
        $this->req('POST', '/api/v1/purchase-returns/'.$pr->json('data.id').'/cancel', ['reason' => 'Supplier refused'])->assertOk();
        $this->assertSame(['36.000', '1440.00'], array_slice($this->onHand(), 0, 2));
    }

    public function test_a_bill_from_an_order_receives_in_the_order_lines_unit(): void
    {
        $po = $this->req('POST', '/api/v1/purchase-orders', ['party_id' => $this->supplier->id, 'order_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->soap->id, 'unit_id' => $this->units['box'], 'quantity' => '10', 'unit_cost' => '480.00']]])->assertCreated()->json('data');
        $body = fn (?int $unit) => ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'PO-'.Str::random(5), 'bill_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'purchase_order_id' => $po['id'], 'lines' => [['product_id' => $this->soap->id, 'unit_id' => $unit, 'quantity' => '4', 'unit_cost' => '480.00', 'purchase_order_line_id' => $po['lines'][0]['id']]]];

        $r = $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $body(null)), 422, 'validation_failed');
        $this->assertSame(['The order line is in box; receive it in box'], $r->json('errors')['lines.0.unit_id'] ?? null);

        $bill = $this->req('POST', '/api/v1/purchase-bills', $body($this->units['box']))->assertCreated()->json('data.id');
        $this->req('POST', "/api/v1/purchase-bills/{$bill}/post")->assertOk();
        $this->assertSame('4.000', (string) DB::table('purchase_order_lines')->where('id', $po['lines'][0]['id'])->value('received_quantity'), 'received in boxes');
        $this->assertSame('48.000', $this->onHand()[0]);
    }
}
