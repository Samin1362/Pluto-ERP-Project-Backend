<?php

namespace Tests\Feature\Sales;

use App\Domain\Sales\SalesInvoiceService;
use App\Models\CompanySetting;
use App\Models\SalesInvoice;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** GET /sales-invoices/{id}/pdf (E4 print; GAP-12 challan variant; backlog P5.1-BE-05). */
class SalesInvoicePdfTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $karim = $this->customer();
        $wh = $this->warehouse();
        $oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $delivery = $this->product('Delivery trip', 'pcs', '150.00', 'service');
        $this->useBooks();
        $this->invoice = app(SalesInvoiceService::class)->createDraft(['party_id' => $karim->id, 'invoice_date' => CarbonImmutable::now('Asia/Dhaka')->toDateString(),
            'warehouse_id' => $wh->id, 'customer_po_ref' => 'PO-2231', 'notes' => 'Deliver before noon',
            'lines' => [['product_id' => $oil->id, 'quantity' => '40', 'discount_pct' => '2'], ['product_id' => $delivery->id, 'quantity' => '2']]]);
        $this->invoice->forceFill(['challan_no' => 'DC-2026-0412', 'vehicle_no' => 'Dhaka Metro-Ta 11-2233'])->saveQuietly();
    }

    private function pdf(string $query = '', ?string $token = null)
    {
        $r = $this->api('GET', "/api/v1/sales-invoices/{$this->invoice->id}/pdf{$query}", $token ?? $this->token, $this->books->id);
        $this->useBooks();

        return $r;
    }

    public function test_the_invoice_and_its_challan_are_pdfs(): void
    {
        $r = $this->pdf()->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertLessThan(150_000, strlen($r->getContent()), 'fonts are subset, not embedded whole');
        $this->assertStringContainsString("filename=\"{$this->invoice->number}.pdf\"", $r->headers->get('Content-Disposition'));

        $c = $this->pdf('?variant=challan')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $c->getContent());
        $this->assertStringContainsString("filename=\"challan-{$this->invoice->number}.pdf\"", $c->headers->get('Content-Disposition'));
    }

    public function test_the_template_shows_prices_only_on_the_invoice(): void
    {
        $render = fn (string $variant) => view('pdf.sales-invoice', ['invoice' => $this->invoice->load(['lines', 'party', 'warehouse']), 'variant' => $variant,
            'company' => $this->books, 'settings' => CompanySetting::first()])->render();
        $invoice = $render('invoice');
        foreach (['Invoice', $this->invoice->number, 'Karim Distributors', 'PO-2231', 'Soybean oil 5L jar', '40 jar', '36,848.00', '37,148.00',
            'Taka thirty-seven thousand one hundred forty-eight only', 'DRAFT', 'Deliver before noon', 'Meghna Traders Ltd.'] as $text) {
            $this->assertStringContainsString($text, $invoice, $text);
        }
        $challan = $render('challan');
        foreach (['Delivery challan', 'DC-2026-0412', 'Dhaka Metro-Ta 11-2233', '40 jar', 'Received by'] as $text) {
            $this->assertStringContainsString($text, $challan, $text);
        }
        foreach (['37,148.00', 'Price (Tk)', 'Taka thirty-seven'] as $text) {
            $this->assertStringNotContainsString($text, $challan, "challan hides {$text}");
        }
    }

    public function test_validation_permissions_and_isolation(): void
    {
        $this->assertProblem($this->pdf('?variant=receipt'), 422, 'validation_failed');
        $this->assertProblem($this->pdf('?colour=red'), 422, 'validation_failed');
        $this->userWithRole('store');
        $this->assertProblem($this->pdf('', $this->login('store@meghna.example')), 403, 'permission_denied');
        $this->userWithRole('viewer');
        $this->pdf('', $this->login('viewer@meghna.example'))->assertOk();
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->assertProblem($this->api('GET', "/api/v1/sales-invoices/{$this->invoice->id}/pdf", $this->login('padma@agro.example'), $other->id), 404, 'not_found');
    }
}
