<?php

namespace Tests\Feature\Settlements;

use App\Domain\Banking\MoneyAccountService;
use App\Domain\Sales\SalesInvoicePostingService;
use App\Domain\Sales\SalesInvoiceService;
use App\Models\CompanySetting;
use App\Models\JournalEntry;
use App\Models\Ledger;
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

/** /customer-receipts (E7, E14; 06 §6; BR-020, BR-021, BR-053, BR-065; TC-SAL-007/008/009; backlog P6.1-BE-03). */
class CustomerReceiptTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $karim;

    private Warehouse $wh;

    private Product $trip;

    private MoneyAccount $sonali;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->karim = $this->customer();
        $this->wh = $this->warehouse();
        $this->trip = $this->product('Delivery trip', 'pcs', '1.00', 'service');
        $this->useBooks();
        $this->sonali = app(MoneyAccountService::class)->create(['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012-4521']);
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

    private function invoice(string $amount, int $dueInDays, ?Party $to = null): SalesInvoice
    {
        $this->useBooks();
        $inv = app(SalesInvoiceService::class)->createDraft(['party_id' => ($to ?? $this->karim)->id, 'invoice_date' => $this->today()->toDateString(),
            'due_date' => $this->today()->addDays($dueInDays)->toDateString(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->trip->id, 'quantity' => $amount, 'unit_price' => '1.00']]]);

        return app(SalesInvoicePostingService::class)->post($inv);
    }

    private function receipt(array $over = [], ?string $token = null)
    {
        return $this->req('POST', '/api/v1/customer-receipts', $over + ['party_id' => $this->karim->id, 'date' => $this->today()->toDateString(), 'amount' => '300000.00',
            'money_account_id' => $this->sonali->id, 'method' => 'bank_transfer'], $token);
    }

    public function test_tc_sal_007_auto_allocation_oldest_first_and_the_rv_entry(): void
    {
        $older = $this->invoice('274700', 5);
        $newer = $this->invoice('118450', 20);
        $r = $this->receipt(['auto_allocate' => true, 'method' => 'cheque', 'cheque_number' => '448133', 'cheque_date' => $this->today()->toDateString(),
            'drawn_on' => 'Islami Bank, Mirpur branch', 'narration' => 'Cheque against INV'])->assertCreated();
        $r->assertJsonPath('data.voucher_number', 'RV-'.$this->today()->year.'-0001')->assertJsonPath('data.entry_type', 'receipt')->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.total_debit', '300000.00')->assertJsonPath('data.amount_in_words', 'Taka three lakh only')
            ->assertJsonPath('data.payment.method', 'cheque')->assertJsonPath('data.payment.cheque_number', '448133')->assertJsonPath('data.payment.unapplied_amount', '0.00')
            ->assertJsonPath('data.payment.party.name', 'Karim Distributors')->assertJsonPath('data.payment.money_account.name', 'Sonali Bank ••4521');
        $this->assertSame([[$older->number, '274700.00'], [$newer->number, '25300.00']],
            array_map(fn ($a) => [$a['document_number'], $a['amount']], $r->json('data.payment.allocations')));
        $this->assertSame([[$this->sonali->ledger->code, '300000.00', '0.00'], [$this->karim->ledger->code, '0.00', '300000.00']],
            array_map(fn ($l) => [$l['ledger_code'], $l['debit'], $l['credit']], $r->json('data.lines')));
        $this->assertSame(['paid', 'part_paid'], [$older->refresh()->payment_status, $newer->refresh()->payment_status]);
        $this->assertSame('93150.00', (string) $newer->balance_due);
        $this->req('GET', "/api/v1/parties/{$this->karim->id}")->assertJsonPath('data.balance', '93150.00');
        $this->req('GET', "/api/v1/money-accounts/{$this->sonali->id}")->assertJsonPath('data.book_balance', '300000.00');
    }

    public function test_tc_sal_008_manual_allocations_are_checked(): void
    {
        $inv = $this->invoice('1000', 5);
        $this->assertProblem($this->receipt(['amount' => '5000.00', 'allocations' => [['document_id' => $inv->id, 'amount' => '1000.01']]]), 422, 'allocation_exceeds_balance');
        $this->assertProblem($this->receipt(['amount' => '500.00', 'allocations' => [['document_id' => $inv->id, 'amount' => '600.00']]]), 422, 'allocation_exceeds_balance')
            ->assertJsonPath('errors.allocations.0', 'The allocations add up to more than the amount received (600.00)');
        $theirs = $this->invoice('700', 5, $this->customer('Rahman Super Shop', '01811000222'));
        $this->assertProblem($this->receipt(['amount' => '700.00', 'allocations' => [['document_id' => $theirs->id, 'amount' => '700.00']]]), 422, 'foreign_document');
        $this->assertSame(0, JournalEntry::where('entry_type', 'receipt')->count(), 'a refused allocation rolls the whole receipt back');
        $this->assertSame(0, DB::table('payment_details')->count());

        $ok = $this->receipt(['amount' => '1500.00', 'allocations' => [['document_id' => $inv->id, 'amount' => '400.00']]])->assertCreated();
        $ok->assertJsonPath('data.payment.unapplied_amount', '1100.00');
        $this->assertSame('part_paid', $inv->refresh()->payment_status);
    }

    public function test_tc_sal_009_excess_stays_as_advance_and_a_later_invoice_takes_it(): void
    {
        $inv = $this->invoice('10000', 5);
        $r = $this->receipt(['amount' => '15000.00', 'auto_allocate' => true])->assertCreated()->assertJsonPath('data.payment.unapplied_amount', '5000.00');
        $later = $this->invoice('3000', 30);
        $credit = $this->req('GET', "/api/v1/parties/{$this->karim->id}/open-documents")->assertJsonPath('data.unapplied_credit', '5000.00')->json('data.credits.0');
        $this->assertSame(['receipt', $r->json('data.voucher_number')], [$credit['kind'], $credit['number']]);
        $this->req('POST', '/api/v1/allocations', ['journal_line_id' => $credit['journal_line_id'], 'document_type' => 'sales_invoice', 'document_id' => $later->id, 'amount' => '3000.00'])->assertCreated();
        $this->assertSame(['paid', 'paid'], [$inv->refresh()->payment_status, $later->refresh()->payment_status]);
        $this->req('GET', '/api/v1/customer-receipts/'.$r->json('data.id'))->assertJsonPath('data.payment.unapplied_amount', '2000.00')->assertJsonCount(2, 'data.payment.allocations');
        $this->assertSame([$r->json('data.voucher_number')], array_column($this->req('GET', '/api/v1/customer-receipts?unapplied=true')->json('data'), 'voucher_number'));
    }

    public function test_bank_charge_deducted_by_the_bank(): void
    {
        $r = $this->receipt(['amount' => '50000.00', 'bank_charge' => '115.00'])->assertCreated();
        $this->assertEqualsCanonicalizing([[$this->sonali->ledger->code, '49885.00', '0.00'], ['6702', '115.00', '0.00'], [$this->karim->ledger->code, '0.00', '50000.00']],
            array_map(fn ($l) => [$l['ledger_code'], $l['debit'], $l['credit']], $r->json('data.lines')));
        $r->assertJsonPath('data.payment.net_received', '49885.00')->assertJsonPath('data.payment.unapplied_amount', '50000.00');
        $this->assertProblem($this->receipt(['amount' => '100.00', 'bank_charge' => '100.00']), 422, 'validation_failed');
    }

    public function test_validation_accounts_and_backdating(): void
    {
        $r = $this->assertProblem($this->req('POST', '/api/v1/customer-receipts', []), 422, 'validation_failed');
        foreach (['party_id', 'date', 'amount', 'money_account_id', 'method'] as $f) {
            $this->assertNotNull($r->json("errors.{$f}"), $f);
        }
        $cheque = $this->assertProblem($this->receipt(['method' => 'cheque']), 422, 'validation_failed');
        foreach (['cheque_number', 'cheque_date', 'drawn_on'] as $f) {
            $this->assertNotNull($cheque->json("errors.{$f}"), $f);
        }
        $this->assertProblem($this->receipt(['amount' => '0']), 422, 'validation_failed');
        $this->assertProblem($this->receipt(['method' => 'barter']), 422, 'validation_failed');
        $this->assertProblem($this->receipt(['party_id' => $this->supplier()->id]), 422, 'validation_failed');
        $this->assertProblem($this->receipt(['exchange_rate' => '110']), 422, 'validation_failed');
        $this->sonali->update(['use_for_receipts' => false]);
        $this->assertProblem($this->receipt(), 422, 'inactive_reference')->assertJsonPath('errors.money_account_id.0', 'Sonali Bank ••4521 is not used for receipts');
        $this->sonali->update(['use_for_receipts' => true]);

        $first = CarbonImmutable::parse(DB::table('accounting_periods')->where('company_id', $this->books->id)->where('status', 'open')->min('start_date'));
        if ($first->diffInDays($this->today()) >= 8) {
            CompanySetting::query()->update(['backdate_warning_days' => 7]);
            $this->assertProblem($this->receipt(['date' => $first->toDateString()]), 422, 'backdated_confirmation_required');
            $this->receipt(['date' => $first->toDateString(), 'confirm_backdated' => true])->assertCreated();
        }
    }

    public function test_list_show_pdf_permissions_and_isolation(): void
    {
        $this->invoice('2000', 5);
        $a = $this->receipt(['amount' => '2000.00', 'auto_allocate' => true])->json('data');
        $rahman = $this->customer('Rahman Super Shop', '01811000222');
        $b = $this->receipt(['party_id' => $rahman->id, 'amount' => '700.00', 'method' => 'bkash', 'transaction_reference' => 'BK8XQ2'])->json('data');
        $numbers = fn (string $q = '') => array_column($this->req('GET', '/api/v1/customer-receipts'.$q)->assertOk()->json('data'), 'voucher_number');
        $this->assertSame([$b['voucher_number'], $a['voucher_number']], $numbers(), 'newest first');
        $this->assertSame([$a['voucher_number']], $numbers("?party_id={$this->karim->id}"));
        $this->assertSame([$b['voucher_number']], $numbers('?q=BK8XQ2'));
        $this->assertSame([$b['voucher_number']], $numbers('?q=rahman'));
        $this->assertSame([$b['voucher_number']], $numbers('?unapplied=true'));
        $this->assertSame([], $this->req('GET', '/api/v1/customer-receipts')->json('data.0.lines'), 'list rows carry no lines');
        $this->assertProblem($this->req('GET', '/api/v1/customer-receipts?sort=colour'), 422, 'validation_failed');

        $pdf = $this->req('GET', "/api/v1/customer-receipts/{$a['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $html = view('pdf.money-receipt', ['v' => $this->req('GET', "/api/v1/customer-receipts/{$a['id']}")->json('data'), 'company' => $this->books])->render();
        foreach (['Money receipt', $a['voucher_number'], 'Karim Distributors', '2,000.00', 'Taka two thousand only', 'Bank transfer'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $journal = JournalEntry::where('entry_type', 'journal')->first(); // not a receipt → 404 on this endpoint
        $this->assertProblem($this->req('GET', '/api/v1/customer-receipts/'.($journal->id ?? 999999)), 404, 'not_found');

        $this->userWithRole('sales');
        $sales = $this->login('sales@meghna.example');
        $this->receipt(['amount' => '10.00'], $sales)->assertCreated();
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->req('GET', '/api/v1/customer-receipts', token: $viewer)->assertOk();
        $this->assertProblem($this->receipt([], $viewer), 403, 'permission_denied');
        $this->userWithRole('store');
        $this->assertProblem($this->req('GET', '/api/v1/customer-receipts', token: $this->login('store@meghna.example')), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/customer-receipts/{$a['id']}", $ot, $other->id), 404, 'not_found');
        $this->assertSame([], $this->api('GET', '/api/v1/customer-receipts', $ot, $other->id)->json('data'));
        $this->useBooks();
        $this->assertSame(Ledger::where('code', '6702')->exists(), true);
    }
}
