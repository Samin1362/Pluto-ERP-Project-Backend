<?php

namespace Tests\Feature\Sales;

use App\Domain\Sales\SalesInvoiceService;
use App\Mail\SalesInvoiceMail;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** POST /sales-invoices/{id}/send (E5 "E-mail to customer"; backlog P5.1-BE-06). */
class SalesInvoiceSendTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private Party $karim;

    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $wh = $this->warehouse();
        $trip = $this->product('Delivery trip', 'pcs', '150.00', 'service'); // no stock needed to post
        $this->useBooks();
        $this->invoice = app(SalesInvoiceService::class)->createDraft(['party_id' => $this->karim->id, 'invoice_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'warehouse_id' => $wh->id, 'lines' => [['product_id' => $trip->id, 'quantity' => '2']]]);
        Mail::fake();
    }

    private function send(array $body, ?string $token = null)
    {
        $r = $this->api('POST', "/api/v1/sales-invoices/{$this->invoice->id}/send", $token ?? $this->token, $this->books->id, $body, ['Idempotency-Key' => (string) Str::uuid()]);
        $this->useBooks();

        return $r;
    }

    private function postInvoice(): void
    {
        $this->api('POST', "/api/v1/sales-invoices/{$this->invoice->id}/post", $this->token, $this->books->id, [], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->useBooks();
    }

    public function test_a_posted_invoice_is_queued_with_its_pdf_and_audited(): void
    {
        $this->postInvoice();
        $this->send(['to' => ['purchase@karim.example'], 'cc' => ['owner@karim.example'], 'message' => 'Payment by cheque is fine.'])->assertStatus(202)->assertNoContent(202);
        Mail::assertQueued(SalesInvoiceMail::class, function (SalesInvoiceMail $m) {
            $number = SalesInvoice::findOrFail($this->invoice->id)->number;
            $this->assertSame("Invoice {$number} from Meghna Traders Ltd.", $m->envelope()->subject);
            $this->assertSame("{$number}.pdf", $m->filename);
            $this->assertStringStartsWith('%PDF', base64_decode($m->pdf));
            $this->assertSame('৳ 300.00', $m->total);
            $body = $m->render();
            $this->assertStringContainsString('Dear Karim Distributors', $body);
            $this->assertStringContainsString('Payment by cheque is fine.', $body);

            return $m->hasTo('purchase@karim.example') && $m->hasCc('owner@karim.example') && count($m->attachments()) === 1;
        });
        $audit = AuditLog::where('action', 'invoice_sent')->where('auditable_type', 'sales_invoices')->where('auditable_id', $this->invoice->id)->sole();
        $this->assertEquals(['to' => ['purchase@karim.example'], 'cc' => ['owner@karim.example']], $audit->new_values);
    }

    public function test_an_empty_list_uses_the_customers_e_mail(): void
    {
        $this->postInvoice();
        $this->assertProblem($this->send(['to' => []]), 422, 'validation_failed')->assertJsonPath('errors.to.0', 'Karim Distributors has no e-mail on file; add an address');
        $this->karim->update(['email' => 'accounts@karim.example']);
        $this->send(['to' => []])->assertStatus(202);
        Mail::assertQueued(SalesInvoiceMail::class, fn (SalesInvoiceMail $m) => $m->hasTo('accounts@karim.example'));
    }

    public function test_only_posted_invoices_validation_permissions_and_isolation(): void
    {
        $this->assertProblem($this->send(['to' => ['a@karim.example']]), 409, 'invalid_transition');
        $this->postInvoice();
        $this->assertProblem($this->send([]), 422, 'validation_failed');
        $this->assertProblem($this->send(['to' => ['not-an-address']]), 422, 'validation_failed');
        $this->assertProblem($this->send(['to' => ['a@karim.example'], 'bcc' => ['x@y.example']]), 422, 'validation_failed');
        $this->userWithRole('viewer');
        $this->assertProblem($this->send(['to' => ['a@karim.example']], $this->login('viewer@meghna.example')), 403, 'permission_denied');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->assertProblem($this->api('POST', "/api/v1/sales-invoices/{$this->invoice->id}/send", $this->login('padma@agro.example'), $other->id, ['to' => ['a@b.example']],
            ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
        Mail::assertNothingQueued();
    }
}
