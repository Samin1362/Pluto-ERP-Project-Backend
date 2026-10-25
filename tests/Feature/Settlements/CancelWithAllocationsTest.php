<?php

namespace Tests\Feature\Settlements;

use App\Domain\Banking\MoneyAccountService;
use App\Domain\Sales\SalesInvoiceService;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Cancel with allocations (BR-024, TC-SAL-012) and the cash sale (06 §5, R-28, TC-SAL-021); backlog P6.1-BE-04. */
class CancelWithAllocationsTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $karim;

    private Warehouse $wh;

    private Product $trip;

    private MoneyAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->wh = $this->warehouse();
        $this->trip = $this->product('Delivery trip', 'pcs', '1.00', 'service');
        $this->useBooks();
        $this->cash = app(MoneyAccountService::class)->create(['account_type' => 'cash']);
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function req(string $method, string $uri, array $data = [])
    {
        $r = $this->api($method, $uri, $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function draft(string $amount): SalesInvoice
    {
        $this->useBooks();

        return app(SalesInvoiceService::class)->createDraft(['party_id' => $this->karim->id, 'invoice_date' => $this->today(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->trip->id, 'quantity' => $amount, 'unit_price' => '1.00']]]);
    }

    public function test_tc_sal_012_cancelling_an_invoice_with_a_receipt_turns_the_receipt_into_an_advance(): void
    {
        $inv = $this->draft('8000');
        $this->req('POST', "/api/v1/sales-invoices/{$inv->id}/post")->assertOk();
        $rv = $this->req('POST', '/api/v1/customer-receipts', ['party_id' => $this->karim->id, 'date' => $this->today(), 'amount' => '5000.00',
            'money_account_id' => $this->cash->id, 'method' => 'cash', 'auto_allocate' => true])->assertCreated()->json('data');
        $this->assertSame('part_paid', $inv->refresh()->payment_status);

        $this->req('POST', "/api/v1/sales-invoices/{$inv->id}/cancel", ['reason' => 'customer_cancelled'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.payment_status', 'unpaid')->assertJsonPath('data.amount_settled', '0.00')
            ->assertJsonPath('data.balance_due', '0.00')->assertJsonPath('data.allocations.0.reversed', true);
        $this->req('GET', "/api/v1/customer-receipts/{$rv['id']}")->assertJsonPath('data.payment.unapplied_amount', '5000.00')->assertJsonPath('data.payment.allocations', []);
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/open-documents")->assertJsonPath('data.documents', [])->assertJsonPath('data.unapplied_credit', '5000.00');
        // The customer is now owed back the 5,000 (credit balance) until it is applied or refunded.
        $this->req('GET', "/api/v1/parties/{$this->karim->id}")->assertJsonPath('data.balance', '5000.00')->assertJsonPath('data.balance_side', 'cr');
    }

    public function test_tc_sal_021_a_cash_sale_posts_the_invoice_and_its_rv_together(): void
    {
        $inv = $this->draft('2500');
        $r = $this->req('POST', "/api/v1/sales-invoices/{$inv->id}/post", ['receipt' => ['money_account_id' => $this->cash->id, 'method' => 'cash', 'amount' => '2500.00']])->assertOk();
        $r->assertJsonPath('data.status', 'posted')->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.balance_due', '0.00')
            ->assertJsonPath('data.allocations.0.kind', 'receipt')->assertJsonPath('data.allocations.0.amount', '2500.00');
        $rv = JournalEntry::where('entry_type', 'receipt')->sole();
        $this->assertSame(["Cash sale {$inv->number}", $this->today()], [$rv->narration, $rv->entry_date->toDateString()]);
        $this->req('GET', "/api/v1/money-accounts/{$this->cash->id}")->assertJsonPath('data.book_balance', '2500.00');
        $this->req('GET', "/api/v1/parties/{$this->karim->id}")->assertJsonPath('data.balance', '0.00');
    }

    public function test_tc_sal_021_either_both_or_neither(): void
    {
        $inv = $this->draft('900');
        $this->assertProblem($this->req('POST', "/api/v1/sales-invoices/{$inv->id}/post", ['receipt' => ['money_account_id' => $this->cash->id, 'method' => 'cash', 'amount' => '800.00']]),
            422, 'validation_failed')->assertJsonPath('errors', ['receipt.amount' => ['A cash sale receives the whole total (900.00)']]);
        $this->cash->update(['use_for_receipts' => false]); // the receipt fails after the invoice part succeeded
        $this->assertProblem($this->req('POST', "/api/v1/sales-invoices/{$inv->id}/post", ['receipt' => ['money_account_id' => $this->cash->id, 'method' => 'cash', 'amount' => '900.00']]),
            422, 'inactive_reference');
        $this->assertSame(['draft', 0, 0], [$inv->refresh()->status, JournalEntry::whereIn('entry_type', ['sales_invoice', 'receipt'])->count(), DB::table('payment_details')->count()]);
        $this->assertProblem($this->req('POST', "/api/v1/sales-invoices/{$inv->id}/post", ['receipt' => ['method' => 'cash', 'amount' => '900.00']]), 422, 'validation_failed');
        $this->cash->update(['use_for_receipts' => true]);
        $this->req('POST', "/api/v1/sales-invoices/{$inv->id}/post", ['receipt' => ['money_account_id' => $this->cash->id, 'method' => 'cash', 'amount' => '900.00']])->assertOk();
    }
}
