<?php

namespace Tests\Feature\Purchases;

use App\Mail\DocumentMail;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Purchase orders: /purchase-orders CRUD, send, close/cancel, convert to a bill, from low stock and PDF (F8, F11, D12;
 * BR-056, BR-006, BR-025; TC-PUR-009; P10.2-BE-01). An order never posts; bills made from it move received_quantity.
 */
class PurchaseOrderTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $supplier;

    private Warehouse $wh;

    private Product $oil;

    private Product $rice;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->supplier = $this->supplier();
        $this->wh = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $this->rice = $this->product('Miniket rice 25kg', 'bag', '1850.00');
        $this->prefix = 'PO-'.FiscalYear::whereDate('start_date', '<=', $this->today())->whereDate('end_date', '>=', $this->today())->value('start_date')->year.'-';
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function body(array $over = []): array
    {
        return $over + ['party_id' => $this->supplier->id, 'order_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '100', 'unit_cost' => '800.00'], ['product_id' => $this->rice->id, 'quantity' => '50', 'unit_cost' => '1600.00']]];
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        if ($method === 'POST' && ! isset($headers['Idempotency-Key'])) {
            $headers['Idempotency-Key'] = (string) Str::uuid();
        }
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function order(array $over = []): int
    {
        return $this->req('POST', '/api/v1/purchase-orders', $this->body($over))->assertCreated()->json('data.id');
    }

    /** Converts the order, sets the supplier's bill number and quantities, posts the bill; returns the bill id. */
    private function receive(int $po, array $quantities): int
    {
        $bill = $this->req('POST', "/api/v1/purchase-orders/{$po}/convert")->assertCreated()->json('data');
        $lines = array_map(fn ($l, $i) => ['product_id' => $l['product_id'], 'quantity' => $quantities[$i] ?? $l['quantity'], 'unit_cost' => $l['unit_cost'],
            'purchase_order_line_id' => $l['purchase_order_line_id']], $bill['lines'], array_keys($bill['lines']));
        $this->req('PUT', "/api/v1/purchase-bills/{$bill['id']}", ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'BEO-'.Str::random(5), 'bill_date' => $this->today(),
            'warehouse_id' => $this->wh->id, 'purchase_order_id' => $po, 'lines' => $lines], ['If-Match' => 'W/"1"'])->assertOk();
        $this->req('POST', "/api/v1/purchase-bills/{$bill['id']}/post")->assertOk();

        return $bill['id'];
    }

    public function test_create_reserves_a_po_number_and_prices_like_a_bill(): void
    {
        $r = $this->req('POST', '/api/v1/purchase-orders', $this->body(['expected_date' => CarbonImmutable::parse($this->today())->addDays(7)->toDateString(),
            'notes' => 'Deliver in the morning']))->assertCreated()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.number', "{$this->prefix}0001")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.party.name', 'Bengal Edible Oils')
            ->assertJsonPath('data.currency_code', 'BDT')->assertJsonPath('data.exchange_rate', '1.000000')->assertJsonPath('data.warehouse_id', $this->wh->id)
            ->assertJsonPath('data.total', '160000.00')->assertJsonPath('data.lines.0.unit_cost', '800.0000')->assertJsonPath('data.lines.0.received_quantity', '0.000')
            ->assertJsonPath('data.lines.0.remaining_quantity', '100.000')->assertJsonPath('data.bills', [])->assertJsonPath('data.notes', 'Deliver in the morning');
        $this->assertSame(0, DB::table('journal_entries')->count(), 'an order never posts (BR-056)');
        $this->assertSame(0, DB::table('stock_movements')->count());

        $this->assertProblem($this->req('POST', '/api/v1/purchase-orders', $this->body(['party_id' => $this->customer()->id])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/purchase-orders', $this->body(['expected_date' => '2020-01-01'])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/purchase-orders', $this->body(['currency_code' => 'USD'])), 422, 'validation_failed')
            ->assertJsonPath('errors.exchange_rate.0', 'Enter the USD to BDT rate (more than 0)');
        $usd = $this->req('POST', '/api/v1/purchase-orders', $this->body(['currency_code' => 'USD', 'exchange_rate' => '121.500000',
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '10', 'unit_cost' => '6.50']]]))->assertCreated();
        $usd->assertJsonPath('data.currency_code', 'USD')->assertJsonPath('data.total_foreign', '65.00')->assertJsonPath('data.total', '7897.50');
        $this->req('GET', '/api/v1/purchase-orders?currency=USD')->assertJsonPath('meta.total', 1);
        $this->req('GET', '/api/v1/purchase-orders?currency=BDT')->assertJsonPath('meta.total', 1);
    }

    public function test_tc_pur_009_bills_from_the_order_move_received_quantity_and_status(): void
    {
        $po = $this->order();
        $this->req('POST', "/api/v1/purchase-orders/{$po}/send", ['to' => ['sales@bengal.example']])->assertOk()->assertJsonPath('data.status', 'sent');

        $bill = $this->req('POST', "/api/v1/purchase-orders/{$po}/convert")->assertCreated();
        $bill->assertJsonPath('data.status', 'draft')->assertJsonPath('data.purchase_order_id', $po)->assertJsonPath('data.supplier_bill_no', "{$this->prefix}0001")
            ->assertJsonPath('data.lines.0.quantity', '100.000')->assertJsonPath('data.lines.0.unit_cost', '800.0000')->assertJsonPath('data.lines.1.quantity', '50.000');
        $this->assertNotNull($bill->json('data.lines.0.purchase_order_line_id'));
        $this->assertProblem($this->req('POST', "/api/v1/purchase-orders/{$po}/convert"), 409, 'invalid_transition')
            ->assertJsonPath('detail', "Everything left on {$this->prefix}0001 is already on draft bill {$bill->json('data.number')}.");
        $this->req('DELETE', '/api/v1/purchase-bills/'.$bill->json('data.id'))->assertNoContent();

        $first = $this->receive($po, ['60', '50']);
        $o = $this->req('GET', "/api/v1/purchase-orders/{$po}")->assertJsonPath('data.status', 'partly_received')
            ->assertJsonPath('data.lines.0.received_quantity', '60.000')->assertJsonPath('data.lines.0.remaining_quantity', '40.000')
            ->assertJsonPath('data.lines.1.remaining_quantity', '0.000');
        $this->assertCount(1, $o->json('data.bills'), 'the deleted draft is gone');
        $this->assertSame(1, PurchaseBill::where('purchase_order_id', $po)->where('status', 'posted')->count());

        $rest = $this->req('POST', "/api/v1/purchase-orders/{$po}/convert")->assertCreated();
        $this->assertCount(1, $rest->json('data.lines'), 'only what is still to come');
        $rest->assertJsonPath('data.lines.0.quantity', '40.000')->assertJsonPath('data.supplier_bill_no', "{$this->prefix}0001");
        $this->req('DELETE', '/api/v1/purchase-bills/'.$rest->json('data.id'))->assertNoContent();
        $this->receive($po, ['40']);
        $this->req('GET', "/api/v1/purchase-orders/{$po}")->assertJsonPath('data.status', 'received');
        $this->assertProblem($this->req('POST', "/api/v1/purchase-orders/{$po}/convert"), 409, 'invalid_transition');

        // Cancelling a bill takes its goods back off the order.
        $this->req('POST', "/api/v1/purchase-bills/{$first}/cancel", ['reason' => 'duplicate'])->assertOk();
        $this->req('GET', "/api/v1/purchase-orders/{$po}")->assertJsonPath('data.status', 'partly_received')
            ->assertJsonPath('data.lines.0.received_quantity', '40.000')->assertJsonPath('data.lines.1.received_quantity', '0.000');
        $this->req('POST', "/api/v1/purchase-orders/{$po}/status", ['status' => 'closed', 'reason' => 'Supplier out of rice'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertProblem($this->req('POST', "/api/v1/purchase-orders/{$po}/convert"), 409, 'invalid_transition');
        $this->assertBooksAreConsistent();
    }

    public function test_a_bill_can_only_link_lines_of_its_own_suppliers_open_order(): void
    {
        $po = $this->order();
        $line = PurchaseOrder::find($po)->lines()->first();
        $base = ['party_id' => $this->supplier->id, 'supplier_bill_no' => 'X-1', 'bill_date' => $this->today(), 'warehouse_id' => $this->wh->id];
        $this->assertSame(['This line is not on the chosen purchase order'], $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $base + ['lines' => [
            ['product_id' => $this->oil->id, 'quantity' => '1', 'unit_cost' => '800', 'purchase_order_line_id' => $line->id]]]), 422, 'validation_failed')
            ->json('errors')['lines.0.purchase_order_line_id']);
        $this->assertSame(['The order line is for a different item'], $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', $base + ['purchase_order_id' => $po,
            'lines' => [['product_id' => $this->rice->id, 'quantity' => '1', 'unit_cost' => '800', 'purchase_order_line_id' => $line->id]]]), 422, 'validation_failed')
            ->json('errors')['lines.0.purchase_order_line_id']);
        $other = $this->supplier('Padma Traders', '01911000444');
        $this->assertProblem($this->req('POST', '/api/v1/purchase-bills', ['party_id' => $other->id] + $base + ['purchase_order_id' => $po,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '1', 'unit_cost' => '800']]]), 422, 'validation_failed')
            ->assertJsonPath('errors.purchase_order_id.0', "{$this->prefix}0001 is another supplier's order");
    }

    public function test_edit_send_close_cancel_and_delete_follow_the_states(): void
    {
        $po = $this->order();
        $this->assertProblem($this->req('PUT', "/api/v1/purchase-orders/{$po}", $this->body()), 428, 'if_match_required');
        $this->req('PUT', "/api/v1/purchase-orders/{$po}", $this->body(['lines' => [['product_id' => $this->oil->id, 'quantity' => '10', 'unit_cost' => '810']]]),
            ['If-Match' => 'W/"1"'])->assertOk()->assertJsonPath('data.total', '8100.00')->assertHeader('ETag', 'W/"2"');
        $this->assertProblem($this->req('POST', "/api/v1/purchase-orders/{$po}/send", ['to' => []]), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', "/api/v1/purchase-orders/{$po}/status", ['status' => 'closed']), 409, 'invalid_transition');
        $this->assertProblem($this->req('POST', "/api/v1/purchase-orders/{$po}/status", ['status' => 'accepted']), 422, 'validation_failed');
        $this->req('POST', "/api/v1/purchase-orders/{$po}/status", ['status' => 'cancelled', 'reason' => 'Ordered elsewhere'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertProblem($this->req('PUT', "/api/v1/purchase-orders/{$po}", $this->body(), ['If-Match' => 'W/"3"']), 409, 'invalid_transition');
        $this->assertProblem($this->req('DELETE', "/api/v1/purchase-orders/{$po}"), 409, 'not_draft');
        $this->assertTrue(AuditLog::where('auditable_type', 'purchase_orders')->where('action', 'cancelled')->exists());

        $this->supplier->update(['email' => 'sales@bengal.example']);
        $b = $this->order();
        $this->req('POST', "/api/v1/purchase-orders/{$b}/send", ['to' => [], 'message' => 'Urgent'])->assertOk()->assertJsonPath('data.status', 'sent');
        Mail::assertQueued(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('sales@bengal.example') && $m->filename === "{$this->prefix}0002.pdf"
            && str_starts_with(base64_decode($m->pdf), '%PDF'));
        $this->receive($b, ['5', '0.001']);
        $this->assertProblem($this->req('POST', "/api/v1/purchase-orders/{$b}/status", ['status' => 'cancelled']), 409, 'invalid_transition');
        $this->assertProblem($this->req('POST', "/api/v1/purchase-orders/{$b}/send", ['to' => []]), 409, 'invalid_transition');

        $c = $this->order();
        $this->req('DELETE', "/api/v1/purchase-orders/{$c}")->assertNoContent();
        $this->assertTrue(AuditLog::where('auditable_type', 'purchase_orders')->where('action', 'voided_number')->exists());
        $this->req('POST', '/api/v1/purchase-orders', $this->body())->assertCreated()->assertJsonPath('data.number', "{$this->prefix}0004");
    }

    public function test_from_low_stock_makes_one_draft_per_preferred_supplier_and_warehouse(): void
    {
        $other = $this->supplier('Padma Traders', '01911000444');
        $sugar = $this->product('Sugar 1kg', 'pcs', '140.00');
        $salt = $this->product('Salt 1kg', 'pcs', '45.00');
        $this->oil->update(['preferred_supplier_id' => $this->supplier->id, 'last_purchase_cost' => '805.5000']);
        $this->rice->update(['preferred_supplier_id' => $this->supplier->id]);
        $sugar->update(['preferred_supplier_id' => $other->id]);
        DB::table('product_warehouse_settings')->insert([
            ['company_id' => $this->books->id, 'product_id' => $this->oil->id, 'warehouse_id' => $this->wh->id, 'reorder_level' => '20', 'reorder_qty' => '120'],
            ['company_id' => $this->books->id, 'product_id' => $this->rice->id, 'warehouse_id' => $this->wh->id, 'reorder_level' => '30', 'reorder_qty' => '0'],
        ]);

        $r = $this->req('POST', '/api/v1/purchase-orders/from-low-stock', ['order_date' => $this->today(), 'rows' => [
            ['product_id' => $this->oil->id, 'warehouse_id' => $this->wh->id],
            ['product_id' => $this->rice->id, 'warehouse_id' => $this->wh->id],
            ['product_id' => $sugar->id, 'warehouse_id' => $this->wh->id, 'quantity' => '200'],
            ['product_id' => $salt->id, 'warehouse_id' => $this->wh->id],
        ]])->assertCreated();
        $this->assertCount(2, $r->json('data.succeeded'));
        $r->assertJsonPath('data.failed.0.id', $salt->id)->assertJsonPath('data.failed.0.detail', 'Salt 1kg has no preferred supplier; order it from a purchase order.');
        $bengal = PurchaseOrder::with('lines')->where('party_id', $this->supplier->id)->firstOrFail();
        $this->assertSame('draft', $bengal->status);
        $this->assertSame('From the low-stock report', $bengal->source_note);
        $this->assertSame(['120.000', '30.000'], $bengal->lines->map(fn ($l) => (string) $l->quantity)->all(), 'reorder qty, else up to the reorder level');
        $this->assertSame('805.5000', (string) $bengal->lines[0]->unit_cost);
        $this->assertSame('200.000', (string) PurchaseOrder::with('lines')->where('party_id', $other->id)->firstOrFail()->lines[0]->quantity);
        $this->assertProblem($this->req('POST', '/api/v1/purchase-orders/from-low-stock', ['order_date' => $this->today(), 'rows' => []]), 422, 'validation_failed');
    }

    public function test_list_pdf_idempotency_permissions_and_isolation(): void
    {
        $key = (string) Str::uuid();
        $a = $this->req('POST', '/api/v1/purchase-orders', $this->body(), ['Idempotency-Key' => $key])->assertCreated();
        $this->req('POST', '/api/v1/purchase-orders', $this->body(), ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $a->json('data.id'));
        $id = $a->json('data.id');
        $this->order(['party_id' => $this->supplier('Padma Traders', '01911000444')->id]);
        $this->req('POST', "/api/v1/purchase-orders/{$id}/send", ['to' => ['a@b.example']])->assertOk();

        $this->req('GET', '/api/v1/purchase-orders')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.lines', []);
        $this->req('GET', '/api/v1/purchase-orders?status=open')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
        $this->req('GET', '/api/v1/purchase-orders?q=Padma')->assertJsonPath('meta.total', 1);
        $this->assertProblem($this->req('GET', '/api/v1/purchase-orders?status=posted'), 422, 'validation_failed');
        $this->assertStringStartsWith('%PDF', $this->req('GET', "/api/v1/purchase-orders/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent());

        $this->userWithRole('sales');
        $sales = $this->login('sales@meghna.example');
        $this->assertProblem($this->req('GET', '/api/v1/purchase-orders', token: $sales), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', "/api/v1/purchase-orders/{$id}", token: $viewer)->assertOk();
        $this->assertProblem($this->req('POST', "/api/v1/purchase-orders/{$id}/convert", token: $viewer), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/purchase-orders/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('POST', "/api/v1/purchase-orders/{$id}/status", $ot, $other->id, ['status' => 'closed']), 404, 'not_found');
        $this->assertSame(0, $this->api('GET', '/api/v1/purchase-orders', $ot, $other->id)->json('meta.total'));
    }
}
