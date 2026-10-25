<?php

namespace Tests\Feature\Settlements;

use App\Domain\Accounting\JournalBuilder;
use App\Domain\Accounting\PostingService;
use App\Domain\Purchases\PurchaseBillPostingService;
use App\Domain\Purchases\PurchaseBillService;
use App\Domain\Sales\SalesInvoicePostingService;
use App\Domain\Sales\SalesInvoiceService;
use App\Domain\Shared\Money;
use App\Models\AuditLog;
use App\Models\Ledger;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\SalesInvoice;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** POST/DELETE /allocations, GET /parties/{id}/open-documents (06 §6; BR-020, BR-021; R-08, R-09; TC-SAL-008/019; P6.1-BE-02). */
class AllocationTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $karim;

    private Warehouse $wh;

    private Product $trip;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->wh = $this->warehouse();
        $this->trip = $this->product('Delivery trip', 'pcs', '1.00', 'service'); // no stock needed
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now('Asia/Dhaka')->startOfDay();
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    /** A posted invoice of `amount` (a service line at ৳ 1), due `dueInDays` from today. */
    private function invoice(string $amount, int $dueInDays = 15, ?Party $to = null): SalesInvoice
    {
        $this->useBooks();
        $inv = app(SalesInvoiceService::class)->createDraft(['party_id' => ($to ?? $this->karim)->id, 'invoice_date' => $this->today()->toDateString(),
            'due_date' => $this->today()->addDays(max($dueInDays, 0))->toDateString(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->trip->id, 'quantity' => $amount, 'unit_price' => '1.00']]]);
        $inv = app(SalesInvoicePostingService::class)->post($inv);
        if ($dueInDays < 0) { // overdue: the due date moved into the past (posted rows keep their amounts; dates are not money)
            DB::table('sales_invoices')->where('id', $inv->id)->update(['invoice_date' => $this->today()->addDays($dueInDays - 1)->toDateString(),
                'due_date' => $this->today()->addDays($dueInDays)->toDateString()]);
        }

        return $inv->refresh();
    }

    /** An advance: a posted journal crediting the customer (debiting a supplier) against cash. Returns its party line id. */
    private function advance(Party $party, string $amount): int
    {
        $this->useBooks();
        $cash = Ledger::where('code', '1101-01')->value('id');
        $b = $party->party_type === 'customer' ? (new JournalBuilder)->debit($cash, Money::of($amount))->credit($party->ledger_id, Money::of($amount))
            : (new JournalBuilder)->debit($party->ledger_id, Money::of($amount))->credit($cash, Money::of($amount));
        $entry = app(PostingService::class)->persist($b, null, 'journal', $this->today(), null, 'Advance');

        return (int) $entry->lines->firstWhere('ledger_id', $party->ledger_id)->id;
    }

    private function allocate(int $line, int $docId, string $amount, string $type = 'sales_invoice', ?string $token = null)
    {
        return $this->req('POST', '/api/v1/allocations', ['journal_line_id' => $line, 'document_type' => $type, 'document_id' => $docId, 'amount' => $amount], $token);
    }

    public function test_tc_sal_019_apply_an_advance_later_then_remove_it(): void
    {
        $line = $this->advance($this->karim, '25300.00');
        $inv = $this->invoice('40000');
        $r = $this->allocate($line, $inv->id, '25300.00')->assertCreated();
        $r->assertJsonPath('data.amount', '25300.00')->assertJsonPath('data.kind', 'advance')->assertJsonPath('data.reversed', false)
            ->assertJsonPath('data.date', $this->today()->toDateString());
        $this->req('GET', "/api/v1/sales-invoices/{$inv->id}")->assertJsonPath('data.payment_status', 'part_paid')->assertJsonPath('data.amount_settled', '25300.00')
            ->assertJsonPath('data.balance_due', '14700.00')->assertJsonPath('data.allocations.0.voucher_number', $r->json('data.voucher_number'));
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/open-documents")->assertJsonPath('data.credits', [])->assertJsonPath('data.unapplied_credit', '0.00')
            ->assertJsonPath('data.total_open', '14700.00');
        $this->assertTrue(AuditLog::where('action', 'allocated')->where('auditable_type', 'sales_invoices')->where('auditable_id', $inv->id)->exists());

        $this->req('DELETE', '/api/v1/allocations/'.$r->json('data.id'))->assertNoContent();
        $this->req('GET', "/api/v1/sales-invoices/{$inv->id}")->assertJsonPath('data.payment_status', 'unpaid')->assertJsonPath('data.balance_due', '40000.00')
            ->assertJsonPath('data.allocations.0.reversed', true);
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/open-documents")->assertJsonPath('data.credits.0.unapplied', '25300.00')->assertJsonPath('data.credits.0.kind', 'advance');
        $this->assertNotNull(DB::table('bill_allocations')->where('id', $r->json('data.id'))->value('reversed_at'), 'the row stays, reversed');
        $this->assertProblem($this->req('DELETE', '/api/v1/allocations/'.$r->json('data.id')), 404, 'not_found');

        // Paid in full by two allocations
        $this->allocate($line, $inv->id, '25300.00')->assertCreated();
        $second = $this->advance($this->karim, '20000.00');
        $this->allocate($second, $inv->id, '14700.00')->assertCreated();
        $this->req('GET', "/api/v1/sales-invoices/{$inv->id}")->assertJsonPath('data.payment_status', 'paid')->assertJsonPath('data.balance_due', '0.00');
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/open-documents")->assertJsonPath('data.documents', [])->assertJsonPath('data.unapplied_credit', '5300.00');
    }

    public function test_tc_sal_008_never_above_a_balance_or_the_credit_and_never_another_partys_document(): void
    {
        $line = $this->advance($this->karim, '5000.00');
        $inv = $this->invoice('3000');
        $this->assertProblem($this->allocate($line, $inv->id, '3000.01'), 422, 'allocation_exceeds_balance')->assertJsonPath('available', '3000.00');
        $big = $this->invoice('9000');
        $this->assertProblem($this->allocate($line, $big->id, '5000.01'), 422, 'allocation_exceeds_balance')->assertJsonPath('available', '5000.00');
        $this->allocate($line, $big->id, '4000.00')->assertCreated();
        $this->assertProblem($this->allocate($line, $inv->id, '1000.01'), 422, 'allocation_exceeds_balance')->assertJsonPath('available', '1000.00');

        $rahman = $this->customer('Rahman Super Shop', '01811000222');
        $theirs = $this->invoice('500', to: $rahman);
        $this->assertProblem($this->allocate($line, $theirs->id, '100.00'), 422, 'foreign_document');
        $supplier = $this->supplier();
        $this->useBooks();
        $bill = app(PurchaseBillService::class)->createDraft(['party_id' => $supplier->id, 'supplier_bill_no' => 'SB-1', 'bill_date' => $this->today()->toDateString(),
            'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->trip->id, 'quantity' => '100', 'unit_cost' => '1']]]);
        app(PurchaseBillPostingService::class)->post($bill);
        $this->assertProblem($this->allocate($line, $bill->id, '10.00', 'purchase_bill'), 422, 'foreign_document');

        $this->useBooks();
        $draft = app(SalesInvoiceService::class)->createDraft(['party_id' => $this->karim->id, 'invoice_date' => $this->today()->toDateString(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->trip->id, 'quantity' => '10', 'unit_price' => '1.00']]]);
        $this->assertProblem($this->allocate($line, $draft->id, '1.00'), 422, 'validation_failed')->assertJsonPath('errors.document_id.0', "{$draft->number} is draft; only a posted document can be settled");
        $invoiceLine = (int) DB::table('journal_lines')->where('journal_entry_id', $inv->journal_entry_id)->where('ledger_id', $this->karim->ledger_id)->value('id');
        $this->assertProblem($this->allocate($invoiceLine, $big->id, '1.00'), 422, 'validation_failed'); // an invoice's own debit cannot settle
        foreach ([['amount' => '0'], ['amount' => '1.234'], ['document_type' => 'quotation'], ['colour' => 'red']] as $bad) {
            $this->assertProblem($this->req('POST', '/api/v1/allocations', $bad + ['journal_line_id' => $line, 'document_type' => 'sales_invoice', 'document_id' => $inv->id, 'amount' => '1.00']), 422, 'validation_failed');
        }
    }

    public function test_open_documents_oldest_due_first_with_days_overdue_and_credits(): void
    {
        $later = $this->invoice('3000', 30);
        $overdue = $this->invoice('2000', -5);
        $soon = $this->invoice('1000', 2);
        $this->advance($this->karim, '1500.00');
        $r = $this->req('GET', "/api/v1/parties/{$this->karim->id}/open-documents")->assertOk();
        $this->assertSame([$overdue->number, $soon->number, $later->number], array_column($r->json('data.documents'), 'number'));
        $this->assertSame([5, 0, 0], array_column($r->json('data.documents'), 'days_overdue'));
        $r->assertJsonPath('data.documents.0.type', 'sales_invoice')->assertJsonPath('data.documents.0.balance', '2000.00')->assertJsonPath('data.total_open', '6000.00')
            ->assertJsonPath('data.unapplied_credit', '1500.00')->assertJsonPath('data.party.code', 'CUS-0001');
        $this->assertProblem($this->req('GET', "/api/v1/parties/{$this->karim->id}/open-documents?amount=10"), 422, 'validation_failed');

        // A supplier's open bills and its debit (payment/advance) side
        $supplier = $this->supplier();
        $this->useBooks();
        $bill = app(PurchaseBillService::class)->createDraft(['party_id' => $supplier->id, 'supplier_bill_no' => 'SB-9', 'bill_date' => $this->today()->toDateString(),
            'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->trip->id, 'quantity' => '700', 'unit_cost' => '1']]]);
        app(PurchaseBillPostingService::class)->post($bill);
        $paid = $this->advance($supplier, '300.00');
        $this->allocate($paid, $bill->id, '300.00', 'purchase_bill')->assertCreated();
        $this->req('GET', "/api/v1/parties/{$supplier->id}/open-documents")->assertJsonPath('data.documents.0.type', 'purchase_bill')
            ->assertJsonPath('data.documents.0.settled', '300.00')->assertJsonPath('data.documents.0.balance', '400.00');
        $this->assertSame('part_paid', PurchaseBill::findOrFail($bill->id)->payment_status);
    }

    public function test_r_09_permission_follows_the_party_and_isolation(): void
    {
        $line = $this->advance($this->karim, '1000.00');
        $inv = $this->invoice('1000');
        $supplier = $this->supplier();
        $this->useBooks();
        $bill = app(PurchaseBillService::class)->createDraft(['party_id' => $supplier->id, 'supplier_bill_no' => 'SB-2', 'bill_date' => $this->today()->toDateString(),
            'warehouse_id' => $this->wh->id, 'lines' => [['product_id' => $this->trip->id, 'quantity' => '50', 'unit_cost' => '1']]]);
        app(PurchaseBillPostingService::class)->post($bill);
        $supLine = $this->advance($supplier, '50.00');

        $this->userWithRole('sales');
        $sales = $this->login('sales@meghna.example');
        $a = $this->allocate($line, $inv->id, '400.00', token: $sales)->assertCreated();
        $this->assertProblem($this->allocate($supLine, $bill->id, '10.00', 'purchase_bill', $sales), 403, 'permission_denied')->assertJsonPath('required_permission', 'purchases.create');
        $this->req('DELETE', '/api/v1/allocations/'.$a->json('data.id'), token: $sales)->assertNoContent();
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->assertProblem($this->allocate($line, $inv->id, '1.00', token: $viewer), 403, 'permission_denied');
        $this->req('GET', "/api/v1/parties/{$this->karim->id}/open-documents", token: $viewer)->assertOk();
        $this->userWithRole('accountant');
        $this->allocate($supLine, $bill->id, '50.00', 'purchase_bill', $this->login('accountant@meghna.example'))->assertCreated();

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('POST', '/api/v1/allocations', $ot, $other->id, ['journal_line_id' => $line, 'document_type' => 'sales_invoice',
            'document_id' => $inv->id, 'amount' => '1.00'], ['Idempotency-Key' => (string) Str::uuid()]), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', "/api/v1/parties/{$this->karim->id}/open-documents", $ot, $other->id), 404, 'not_found');
        $keep = $this->allocate($line, $inv->id, '1.00')->json('data.id');
        $this->assertProblem($this->api('DELETE', "/api/v1/allocations/{$keep}", $ot, $other->id), 404, 'not_found');
        $this->useBooks();
    }

    public function test_tc_db_004_an_allocation_targets_exactly_one_document(): void
    {
        $line = $this->advance($this->karim, '10.00');
        $this->expectException(QueryException::class);
        DB::table('bill_allocations')->insert(['company_id' => $this->books->id, 'journal_line_id' => $line, 'amount' => '1.00', 'allocated_on' => $this->today()->toDateString(),
            'created_at' => now(), 'updated_at' => now()]);
    }
}
