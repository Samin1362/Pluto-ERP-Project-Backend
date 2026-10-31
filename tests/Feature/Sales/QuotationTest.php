<?php

namespace Tests\Feature\Sales;

use App\Mail\DocumentMail;
use App\Models\AuditLog;
use App\Models\FiscalYear;
use App\Models\Party;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Quotations: /quotations CRUD, send, status, convert and PDF (E10, E13; BR-056, BR-006, BR-025; TC-SAL-014;
 * P10.1-BE-01). A quotation never posts; an accepted one converts once into a draft invoice.
 */
class QuotationTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $karim;

    private Warehouse $wh;

    private Product $oil;

    private string $prefix;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->wh = $this->warehouse();
        $this->oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $this->prefix = 'QT-'.FiscalYear::whereDate('start_date', '<=', $this->today())->whereDate('end_date', '>=', $this->today())->value('start_date')->year.'-';
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function body(array $over = []): array
    {
        return $over + ['party_id' => $this->karim->id, 'quotation_date' => $this->today(), 'valid_until' => CarbonImmutable::parse($this->today())->addDays(15)->toDateString(),
            'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->oil->id, 'quantity' => '40']]];
    }

    private function req(string $method, string $uri, array $data = [], array $headers = [], ?string $token = null)
    {
        if ($method === 'POST') {
            $headers += ['Idempotency-Key' => (string) Str::uuid()];
        }
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $headers);
        $this->useBooks();

        return $r;
    }

    private function quote(array $over = []): int
    {
        return $this->req('POST', '/api/v1/quotations', $this->body($over))->assertCreated()->json('data.id');
    }

    public function test_create_reserves_a_qt_number_and_prices_like_an_invoice(): void
    {
        $r = $this->req('POST', '/api/v1/quotations', $this->body(['notes' => 'Prices hold for 15 days', 'lines' => [
            ['product_id' => $this->oil->id, 'quantity' => '40'],
            ['product_id' => $this->oil->id, 'quantity' => '10', 'unit_price' => '950.00', 'discount_pct' => '2', 'description' => 'Promo carton'],
        ]]))->assertCreated()->assertHeader('ETag', 'W/"1"');
        $r->assertJsonPath('data.number', "{$this->prefix}0001")->assertJsonPath('data.status', 'draft')->assertJsonPath('data.party.name', 'Karim Distributors')
            ->assertJsonPath('data.warehouse_id', $this->wh->id)->assertJsonPath('data.notes', 'Prices hold for 15 days')->assertJsonPath('data.sent_at', null)
            ->assertJsonPath('data.subtotal', '47100.00')->assertJsonPath('data.discount_total', '190.00')->assertJsonPath('data.total', '46910.00')
            ->assertJsonPath('data.lines.0.unit_price', '940.00')->assertJsonPath('data.lines.1.net_amount', '9310.00')->assertJsonPath('data.lines.1.description', 'Promo carton')
            ->assertJsonPath('data.converted_invoice_id', null)->assertJsonPath('data.created_by.name', 'Owner')->assertJsonPath('data.version', 1);
        $this->assertSame(0, DB::table('journal_entries')->count(), 'a quotation never posts (BR-056)');
        $this->assertSame(0, DB::table('stock_movements')->count());
        $this->assertTrue(AuditLog::where('auditable_type', 'quotations')->where('action', 'created')->exists());

        $this->assertProblem($this->req('POST', '/api/v1/quotations', $this->body(['valid_until' => '2020-01-01'])), 422, 'validation_failed')
            ->assertJsonPath('errors.valid_until.0', 'Valid until cannot be before the quotation date');
        $this->assertProblem($this->req('POST', '/api/v1/quotations', $this->body(['lines' => []])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/quotations', $this->body(['party_id' => $this->supplier()->id])), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/quotations', $this->body(['quotation_date' => '2001-01-01', 'valid_until' => '2001-02-01'])), 422, 'validation_failed')
            ->assertJsonPath('errors.quotation_date.0', 'No financial year covers this date; create the year in Settings › Financial years first');
        $this->karim->update(['status' => 'inactive']);
        $this->assertProblem($this->req('POST', '/api/v1/quotations', $this->body()), 422, 'inactive_reference');
    }

    public function test_a_prospect_can_be_quoted_and_becomes_active_when_converted(): void
    {
        $p = $this->req('POST', '/api/v1/parties', ['party_type' => 'customer', 'name' => 'Nilambari Hotel', 'mobile' => '01711000777', 'status' => 'prospect'])
            ->assertCreated()->assertJsonPath('data.status', 'prospect');
        $this->assertProblem($this->req('POST', '/api/v1/parties', ['party_type' => 'supplier', 'name' => 'X Oils', 'mobile' => '01711000778', 'status' => 'prospect']), 422, 'validation_failed');
        $this->assertProblem($this->req('PATCH', '/api/v1/parties/'.$p->json('data.id'), ['status' => 'active'], ['If-Match' => 'W/"1"']), 422, 'validation_failed');

        $id = $this->quote(['party_id' => $p->json('data.id')]);
        $this->req('POST', "/api/v1/quotations/{$id}/status", ['status' => 'accepted'])->assertOk()->assertJsonPath('data.status', 'accepted');
        $inv = $this->req('POST', "/api/v1/quotations/{$id}/convert")->assertCreated();
        $inv->assertJsonPath('data.status', 'draft')->assertJsonPath('data.party.name', 'Nilambari Hotel');
        $this->assertSame('active', Party::find($p->json('data.id'))->status);
    }

    public function test_tc_sal_014_convert_an_accepted_quotation_once(): void
    {
        $id = $this->quote(['lines' => [['product_id' => $this->oil->id, 'quantity' => '12', 'unit_price' => '955.50', 'discount_pct' => '1.5', 'description' => 'Festival rate']]]);
        $this->assertProblem($this->req('POST', "/api/v1/quotations/{$id}/convert"), 409, 'invalid_transition');
        $this->req('POST', "/api/v1/quotations/{$id}/status", ['status' => 'accepted', 'reason' => 'Confirmed by phone'])->assertOk();

        $r = $this->req('POST', "/api/v1/quotations/{$id}/convert")->assertCreated();
        $r->assertJsonPath('data.status', 'draft')->assertJsonPath('data.invoice_date', $this->today())->assertJsonPath('data.warehouse_id', $this->wh->id)
            ->assertJsonPath('data.lines.0.quantity', '12.000')->assertJsonPath('data.lines.0.unit_price', '955.50')->assertJsonPath('data.lines.0.discount_pct', '1.5000')
            ->assertJsonPath('data.lines.0.description', 'Festival rate');
        $q = Quotation::find($id);
        $this->assertSame('converted', $q->status);
        $this->assertSame((string) $q->total, $r->json('data.total'));
        $this->assertSame($id, SalesInvoice::find($r->json('data.id'))->quotation_id);
        $this->req('GET', "/api/v1/quotations/{$id}")->assertJsonPath('data.converted_invoice_id', $r->json('data.id'))
            ->assertJsonPath('data.converted_invoice_number', $r->json('data.number'));

        // E6 saves the draft without quotation_id: the link stays (found by the P10 acceptance E2E)
        $this->req('PUT', '/api/v1/sales-invoices/'.$r->json('data.id'), ['party_id' => $this->karim->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil->id, 'quantity' => '10', 'unit_price' => '955.50']]], ['If-Match' => 'W/"'.$r->json('data.version').'"'])->assertOk();
        $this->assertSame($id, SalesInvoice::find($r->json('data.id'))->quotation_id);
        $this->req('GET', "/api/v1/quotations/{$id}")->assertJsonPath('data.converted_invoice_id', $r->json('data.id'));

        $this->assertProblem($this->req('POST', "/api/v1/quotations/{$id}/convert"), 409, 'already_converted');
        $this->assertProblem($this->req('POST', "/api/v1/quotations/{$id}/status", ['status' => 'declined']), 409, 'invalid_transition');
        $this->assertProblem($this->req('PUT', "/api/v1/quotations/{$id}", $this->body(), ['If-Match' => 'W/"'.$q->lock_version.'"']), 409, 'invalid_transition');
        $this->assertSame(1, SalesInvoice::count());
        $this->assertSame(0, DB::table('journal_entries')->count(), 'neither the quotation nor the draft invoice posts');
    }

    public function test_edit_needs_the_version_and_works_while_draft_or_sent(): void
    {
        $id = $this->quote();
        $this->assertProblem($this->req('PUT', "/api/v1/quotations/{$id}", $this->body()), 428, 'if_match_required');
        $this->assertProblem($this->req('PUT', "/api/v1/quotations/{$id}", $this->body(), ['If-Match' => 'W/"9"']), 409, 'version_conflict');
        $this->req('PUT', "/api/v1/quotations/{$id}", $this->body(['lines' => [['product_id' => $this->oil->id, 'quantity' => '5']]]), ['If-Match' => 'W/"1"'])
            ->assertOk()->assertHeader('ETag', 'W/"2"')->assertJsonPath('data.total', '4700.00')->assertJsonCount(1, 'data.lines');

        $this->karim->update(['email' => 'accounts@karim.example']);
        $this->req('POST', "/api/v1/quotations/{$id}/send", ['to' => []])->assertOk()->assertJsonPath('data.status', 'sent');
        $v = Quotation::find($id)->lock_version;
        $this->req('PUT', "/api/v1/quotations/{$id}", $this->body(), ['If-Match' => "W/\"{$v}\""])->assertOk()->assertJsonPath('data.status', 'sent');
        $this->req('POST', "/api/v1/quotations/{$id}/status", ['status' => 'declined'])->assertOk();
        $v = Quotation::find($id)->lock_version;
        $this->assertProblem($this->req('PUT', "/api/v1/quotations/{$id}", $this->body(), ['If-Match' => "W/\"{$v}\""]), 409, 'invalid_transition');
        $this->assertProblem($this->req('POST', "/api/v1/quotations/{$id}/send", ['to' => []]), 409, 'invalid_transition');
        $this->assertProblem($this->req('POST', "/api/v1/quotations/{$id}/status", ['status' => 'closed']), 422, 'validation_failed');
    }

    public function test_send_queues_the_pdf_and_marks_it_sent(): void
    {
        $id = $this->quote();
        $this->assertProblem($this->req('POST', "/api/v1/quotations/{$id}/send", ['to' => []]), 422, 'validation_failed')
            ->assertJsonPath('errors.to.0', 'Karim Distributors has no e-mail on file; add an address');
        $this->assertSame('draft', Quotation::find($id)->status);

        $this->req('POST', "/api/v1/quotations/{$id}/send", ['to' => ['buyer@karim.example'], 'message' => 'As discussed'])->assertOk()
            ->assertJsonPath('data.status', 'sent');
        $this->assertNotNull(Quotation::find($id)->sent_at);
        Mail::assertQueued(DocumentMail::class, fn (DocumentMail $m) => $m->hasTo('buyer@karim.example') && $m->filename === "{$this->prefix}0001.pdf"
            && str_starts_with(base64_decode($m->pdf), '%PDF') && $m->note === 'As discussed');
        $this->assertTrue(AuditLog::where('auditable_type', 'quotations')->where('action', 'quotation_sent')->exists());
    }

    public function test_only_drafts_are_deleted_and_the_number_is_voided(): void
    {
        $a = $this->quote();
        $b = $this->quote();
        $this->req('POST', "/api/v1/quotations/{$b}/status", ['status' => 'accepted'])->assertOk();
        $this->assertProblem($this->req('DELETE', "/api/v1/quotations/{$b}"), 409, 'not_draft');
        $this->req('DELETE', "/api/v1/quotations/{$a}")->assertNoContent();
        $this->assertNull(Quotation::find($a));
        $this->assertTrue(AuditLog::where('auditable_type', 'quotations')->where('action', 'voided_number')->exists());
        $this->req('POST', '/api/v1/quotations', $this->body())->assertCreated()->assertJsonPath('data.number', "{$this->prefix}0003");
    }

    public function test_list_filters_search_sort_and_pdf(): void
    {
        $rahim = $this->customer('Rahim Store', '01711000902');
        $a = $this->quote();
        $this->quote(['party_id' => $rahim->id]);
        $this->req('POST', "/api/v1/quotations/{$a}/status", ['status' => 'accepted'])->assertOk();

        $this->req('GET', '/api/v1/quotations')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.lines', []);
        $this->req('GET', '/api/v1/quotations?status=accepted')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a);
        $this->req('GET', '/api/v1/quotations?q=Rahim')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.party.name', 'Rahim Store');
        $this->req('GET', '/api/v1/quotations?sort=number')->assertJsonPath('data.0.number', "{$this->prefix}0001");
        $this->assertProblem($this->req('GET', '/api/v1/quotations?status=posted'), 422, 'validation_failed');

        $pdf = $this->req('GET', "/api/v1/quotations/{$a}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_idempotency_permissions_and_isolation(): void
    {
        $key = (string) Str::uuid();
        $a = $this->req('POST', '/api/v1/quotations', $this->body(), ['Idempotency-Key' => $key])->assertCreated();
        $this->req('POST', '/api/v1/quotations', $this->body(), ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.id', $a->json('data.id'));
        $this->assertSame(1, Quotation::count());
        $id = $a->json('data.id');

        $this->userWithRole('sales');
        $sales = $this->login('sales@meghna.example');
        $this->req('POST', '/api/v1/quotations', $this->body(), token: $sales)->assertCreated();
        $this->assertProblem($this->req('DELETE', "/api/v1/quotations/{$id}", token: $sales), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', '/api/v1/quotations', token: $viewer)->assertOk();
        $this->assertProblem($this->req('POST', '/api/v1/quotations', $this->body(), token: $viewer), 403, 'permission_denied');
        $this->assertProblem($this->req('POST', "/api/v1/quotations/{$id}/status", ['status' => 'accepted'], token: $viewer), 403, 'permission_denied');
        $this->userWithRole('store');
        $store = $this->login('store@meghna.example');
        $this->assertProblem($this->req('GET', "/api/v1/quotations/{$id}", token: $store), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/quotations/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('POST', "/api/v1/quotations/{$id}/convert", $ot, $other->id, [], ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
        $this->assertSame(0, $this->api('GET', '/api/v1/quotations', $ot, $other->id)->json('meta.total'));
    }
}
