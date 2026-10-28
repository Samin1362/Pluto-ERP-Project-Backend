<?php

namespace Tests\Feature\Reports;

use App\Models\Party;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Trade reports and overviews: GET /reports/sales, /reports/sales-returns, /reports/purchases, /reports/purchase-returns,
 * /sales/overview, /purchases/overview (E1, E11, E12, F1, F9, F10; 06 §17; DEC-13; P10.5-BE-01).
 *
 * The month: bought 100 oil at 800 and 50 rice at 1,500 (Bengal); sold Karim 20 oil at 940 and Rahim 10 rice at 1,850;
 * Karim returned 2 oil (restocked); Rahim got a 300 goodwill credit note; 5 oil went back to Bengal.
 */
class TradeReportTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $karim;

    private Party $rahim;

    private Party $bengal;

    private Warehouse $wh;

    private Product $oil;

    private Product $rice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->rahim = $this->customer('Rahim Store', '01711000902');
        $this->bengal = $this->supplier();
        $this->wh = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $this->rice = $this->product('Miniket rice 25kg', 'bag', '1850.00');
        $c = $this->books->id;
        $edible = DB::table('product_categories')->insertGetId(['company_id' => $c, 'name' => 'Edible oil', 'created_at' => now(), 'updated_at' => now()]);
        $grain = DB::table('product_categories')->insertGetId(['company_id' => $c, 'name' => 'Rice and grain', 'created_at' => now(), 'updated_at' => now()]);
        $this->oil->update(['category_id' => $edible]);
        $this->rice->update(['category_id' => $grain]);

        $bill = $this->postDoc('purchase-bills', ['party_id' => $this->bengal->id, 'supplier_bill_no' => 'BEO-1', 'bill_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '100', 'unit_cost' => '800.00'], ['product_id' => $this->rice->id, 'quantity' => '50', 'unit_cost' => '1500.00']]]);
        $karim = $this->postDoc('sales-invoices', ['party_id' => $this->karim->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '20']]]);
        $this->postDoc('sales-invoices', ['party_id' => $this->rahim->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->rice->id, 'quantity' => '10']]]);
        $this->postDoc('sales-returns', ['source_document_id' => $karim['id'], 'return_date' => $this->today(), 'warehouse_id' => $this->wh->id, 'stock_action' => 'restock',
            'usage' => 'credit', 'lines' => [['source_line_id' => $karim['lines'][0]['id'], 'quantity' => '2', 'reason' => 'leaking']]]);
        $note = DB::table('credit_notes')->where('company_id', $c)->value('id');
        $this->req('POST', "/api/v1/credit-notes/{$note}/post")->assertOk();
        $goodwill = $this->req('POST', '/api/v1/credit-notes', ['party_id' => $this->rahim->id, 'note_date' => $this->today(), 'reason' => 'goodwill', 'amount' => '300.00',
            'usage' => 'credit'])->json('data.id');
        $this->req('POST', "/api/v1/credit-notes/{$goodwill}/post")->assertOk();
        $this->postDoc('purchase-returns', ['source_document_id' => $bill['id'], 'return_date' => $this->today(), 'warehouse_id' => $this->wh->id, 'stock_action' => 'send_back',
            'usage' => 'credit', 'lines' => [['source_line_id' => $bill['lines'][0]['id'], 'quantity' => '5', 'reason' => 'broken']]]);
        $this->req('POST', '/api/v1/quotations', ['party_id' => $this->karim->id, 'quotation_date' => $this->today(), 'valid_until' => $this->today(),
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '1']]])->assertCreated();
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
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

    /** Creates and posts a document; returns its draft data (ids, lines). */
    private function postDoc(string $resource, array $body): array
    {
        $d = $this->req('POST', "/api/v1/{$resource}", $body)->assertCreated()->json('data');
        $this->req('POST', "/api/v1/{$resource}/{$d['id']}/post")->assertOk();

        return $d;
    }

    private function row(array $rows, string $label): array
    {
        return collect($rows)->firstWhere('label', $label) ?? $this->fail("No row {$label}");
    }

    public function test_sales_report_by_customer_category_and_month_with_margin(): void
    {
        $r = $this->req('GET', '/api/v1/reports/sales')->assertOk();
        $r->assertJsonPath('data.title', 'Sales report')->assertJsonPath('data.totals.count', 2)->assertJsonPath('data.totals.gross', '37300.00')
            ->assertJsonPath('data.totals.returns', '2180.00')->assertJsonPath('data.totals.net', '35120.00')->assertJsonPath('data.checks.0.ok', true)
            ->assertJsonPath('data.meta.margin_shown', true);
        $karim = $this->row($r->json('data.rows'), 'Karim Distributors');
        $this->assertSame(['18800.00', '1880.00', '16920.00', '48.2'], [$karim['gross'], $karim['returns'], $karim['net'], $karim['share']]);
        $this->assertSame(['14400.00', '2520.00'], [$karim['cogs'], $karim['margin']], 'COGS 20×800 less 2×800 restocked');
        $this->assertSame('300.00', $this->row($r->json('data.rows'), 'Rahim Store')['returns'], 'the goodwill note');
        $r->assertJsonPath('data.totals.cogs', '29400.00')->assertJsonPath('data.totals.margin', '5720.00');

        $cat = $this->req('GET', '/api/v1/reports/sales?group_by=category')->json('data.rows');
        $this->assertSame('16920.00', $this->row($cat, 'Edible oil')['net']);
        $this->assertSame('18500.00', $this->row($cat, 'Rice and grain')['net']);
        $this->assertSame('300.00', $this->row($cat, 'Notes (not by item)')['returns']);
        $month = $this->req('GET', '/api/v1/reports/sales?group_by=month')->json('data.rows');
        $this->assertCount(1, $month);
        $this->req('GET', '/api/v1/reports/sales?party_id='.$this->rahim->id)->assertJsonPath('data.totals.net', '18200.00');
        $this->req('GET', '/api/v1/reports/sales?date_from=2020-01-01&date_to=2020-01-31')->assertJsonPath('data.rows', [])->assertJsonPath('data.totals.net', '0.00');
        $this->assertProblem($this->req('GET', '/api/v1/reports/sales?group_by=salesperson'), 422, 'validation_failed');
        $this->assertProblem($this->req('GET', '/api/v1/reports/sales?group_by=supplier'), 422, 'validation_failed');
    }

    public function test_dec_13_sales_staff_see_sales_but_no_margin_or_balances(): void
    {
        $this->userWithRole('sales');
        $sales = $this->login('sales@meghna.example');
        $r = $this->req('GET', '/api/v1/reports/sales', token: $sales)->assertOk()->assertJsonPath('data.meta.margin_shown', false)->assertJsonPath('data.totals.net', '35120.00');
        $this->assertArrayNotHasKey('margin', $r->json('data.totals'));
        $this->assertArrayNotHasKey('cogs', $r->json('data.rows.0'));
        $this->assertNotContains('margin', array_column($r->json('data.columns'), 'key'));
        $this->req('GET', '/api/v1/sales/overview', token: $sales)->assertOk()->assertJsonPath('data.totals.outstanding', null);
        $this->req('GET', '/api/v1/sales/overview')->assertJsonPath('data.totals.outstanding', '37300.00'); // both notes were kept as credit, not applied
    }

    public function test_return_reports_list_every_return_with_its_note_and_reasons(): void
    {
        $s = $this->req('GET', '/api/v1/reports/sales-returns')->assertOk();
        $s->assertJsonPath('data.totals.count', 1)->assertJsonPath('data.totals.value', '1880.00')->assertJsonPath('data.rows.0.party', 'Karim Distributors')
            ->assertJsonPath('data.rows.0.reason', 'leaking')->assertJsonPath('data.rows.0.note_status', 'posted')->assertJsonPath('data.meta.by_reason.0.share', '100.0');
        $this->req('GET', '/api/v1/reports/sales-returns?reason=expired')->assertJsonPath('data.rows', []);
        $p = $this->req('GET', '/api/v1/reports/purchase-returns')->assertOk();
        $p->assertJsonPath('data.rows.0.party', 'Bengal Edible Oils')->assertJsonPath('data.rows.0.value', '4000.00')->assertJsonPath('data.rows.0.reason', 'broken')
            ->assertJsonPath('data.columns.6.label', 'Debit note');
        $this->assertProblem($this->req('GET', '/api/v1/reports/purchase-returns?reason=leaking_badly'), 422, 'validation_failed');
    }

    public function test_purchase_report_and_overviews(): void
    {
        $p = $this->req('GET', '/api/v1/reports/purchases')->assertOk();
        $p->assertJsonPath('data.totals.gross', '155000.00')->assertJsonPath('data.totals.returns', '4000.00')->assertJsonPath('data.totals.net', '151000.00')
            ->assertJsonPath('data.rows.0.label', 'Bengal Edible Oils')->assertJsonPath('data.rows.0.invoices', 1)->assertJsonPath('data.meta.margin_shown', false);
        $this->assertSame('76000.00', $this->row($this->req('GET', '/api/v1/reports/purchases?group_by=category')->json('data.rows'), 'Edible oil')['net']);

        $so = $this->req('GET', '/api/v1/sales/overview')->assertOk();
        $so->assertJsonPath('data.totals.value', '37300.00')->assertJsonPath('data.totals.count', 2)->assertJsonPath('data.totals.open_quotes', 1)
            ->assertJsonPath('data.meta.status.unpaid.count', 2)->assertJsonPath('data.rows.0.name', 'Soybean oil 5L jar'); // 18,800 vs rice 18,500
        $this->assertCount(12, $so->json('data.meta.months'));
        $this->assertCount(2, $so->json('data.meta.recent'));
        $po = $this->req('GET', '/api/v1/purchases/overview?period=this_year')->assertOk();
        $po->assertJsonPath('data.totals.value', '155000.00')->assertJsonPath('data.rows.0.name', 'Bengal Edible Oils')->assertJsonPath('data.totals.outstanding', '155000.00'); // the debit note was kept as credit
        $this->assertProblem($this->req('GET', '/api/v1/sales/overview?period=forever'), 422, 'validation_failed');
    }

    public function test_exports_permissions_and_isolation(): void
    {
        $x = $this->req('GET', '/api/v1/reports/sales?format=xlsx')->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('Content-Type'));
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', '/api/v1/reports/purchases', token: $viewer)->assertOk();
        $this->userWithRole('store');
        $store = $this->login('store@meghna.example');
        $this->assertProblem($this->req('GET', '/api/v1/reports/sales', token: $store), 403, 'permission_denied');
        $this->assertProblem($this->req('GET', '/api/v1/sales/overview', token: $store), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->api('GET', '/api/v1/reports/sales', $ot, $other->id)->assertOk()->assertJsonPath('data.rows', []);
        $this->assertProblem($this->api('GET', '/api/v1/reports/sales?party_id='.$this->karim->id, $ot, $other->id), 422, 'validation_failed');
    }
}
