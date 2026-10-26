<?php

namespace Tests\Feature\Purchases;

use App\Domain\Banking\MoneyAccountService;
use App\Domain\Purchases\PurchaseBillCancellationService;
use App\Domain\Purchases\PurchaseBillPostingService;
use App\Domain\Purchases\PurchaseBillService;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\PurchaseBill;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** /supplier-payments (F5, F12; 06 §9; BR-007, BR-020, BR-024, BR-031, BR-065; TC-PUR-006/007; backlog P8.1-BE-01/02). */
class SupplierPaymentTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $bengal;

    private Warehouse $wh;

    private int $oil;

    private MoneyAccount $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->bengal = $this->supplier();
        $this->wh = $this->warehouse();
        $this->oil = $this->product()->id;
        $this->useBooks();
        $this->bank = app(MoneyAccountService::class)->create(['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012-4521', 'opening_balance' => '500000.00']);
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    /** A posted bill of `$qty` jars at ৳ 100 (no tax), due in `$dueDays`. */
    private function bill(string $qty, int $dueDays = 30, ?Party $from = null): PurchaseBill
    {
        $this->useBooks();
        $bill = app(PurchaseBillService::class)->createDraft(['party_id' => ($from ?? $this->bengal)->id, 'supplier_bill_no' => 'B-'.Str::random(6), 'bill_date' => $this->today(),
            'due_date' => CarbonImmutable::now('Asia/Dhaka')->addDays(max($dueDays, 0))->toDateString(), 'warehouse_id' => $this->wh->id,
            'lines' => [['product_id' => $this->oil, 'quantity' => $qty, 'unit_cost' => '100.00']]]);
        $bill = app(PurchaseBillPostingService::class)->post($bill);
        if ($dueDays < 0) {
            DB::table('purchase_bills')->where('id', $bill->id)->update(['bill_date' => CarbonImmutable::now('Asia/Dhaka')->addDays($dueDays - 1)->toDateString(),
                'due_date' => CarbonImmutable::now('Asia/Dhaka')->addDays($dueDays)->toDateString()]); // dates are not money: an overdue bill
        }

        return $bill->refresh();
    }

    private function body(array $over = []): array
    {
        return $over + ['party_id' => $this->bengal->id, 'date' => $this->today(), 'amount' => '8000.00', 'money_account_id' => $this->bank->id, 'method' => 'bank_transfer',
            'transaction_reference' => 'BEFTN-77812'];
    }

    private function balance(int $ledgerId): string
    {
        $this->useBooks();

        return (string) DB::table('journal_lines')->where('ledger_id', $ledgerId)->where('is_posted', 1)->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b');
    }

    public function test_a_payment_posts_a_pv_and_settles_the_bill(): void
    {
        $bill = $this->bill('80');
        $r = $this->req('POST', '/api/v1/supplier-payments', $this->body(['bank_charge' => '25.00', 'allocations' => [['document_id' => $bill->id, 'amount' => '8000.00']]]))
            ->assertCreated();
        $r->assertJsonPath('data.status', 'posted')->assertJsonPath('data.entry_type', 'payment')->assertJsonPath('data.payment.direction', 'out')
            ->assertJsonPath('data.payment.party.id', $this->bengal->id)->assertJsonPath('data.payment.amount', '8000.00')->assertJsonPath('data.payment.bank_charge', '25.00')
            ->assertJsonPath('data.payment.unapplied_amount', '0.00')->assertJsonPath('data.payment.allocations.0.document_number', $bill->number)
            ->assertJsonPath('data.narration', 'Paid to Bengal Edible Oils')->assertJsonPath('data.total_debit', '8025.00');
        $this->assertMatchesRegularExpression('/^PV-\d{4}-0001$/', $r->json('data.voucher_number'));
        $this->assertSame(['0.00', 'paid'], [(string) $bill->refresh()->balance_due, $bill->payment_status]);
        $this->assertSame('0.00', $this->balance($this->bengal->ledger_id));
        $this->assertSame('491975.00', $this->balance($this->bank->ledger_id), '5,00,000 − 8,000 − 25 charge (BR-065)');
    }

    public function test_auto_allocation_pays_the_oldest_bill_first_and_keeps_the_rest_as_an_advance(): void
    {
        $new = $this->bill('30', 20);
        $old = $this->bill('50', -5);
        $r = $this->req('POST', '/api/v1/supplier-payments', $this->body(['amount' => '9000.00', 'auto_allocate' => true]))->assertCreated();
        $this->assertEquals([$old->number => '5000.00', $new->number => '3000.00'], collect($r->json('data.payment.allocations'))->pluck('amount', 'document_number')->all());
        $r->assertJsonPath('data.payment.unapplied_amount', '1000.00');
        $this->assertSame('1000.00', $this->balance($this->bengal->ledger_id), 'an advance with the supplier (Dr)');
    }

    public function test_t_c_pu_r_007_more_than_the_account_holds_is_refused(): void
    {
        $this->useBooks();
        $cash = app(MoneyAccountService::class)->create(['account_type' => 'cash', 'opening_balance' => '1000.00']);
        $this->assertProblem($this->req('POST', '/api/v1/supplier-payments', $this->body(['money_account_id' => $cash->id, 'amount' => '1500.00', 'method' => 'cash'])), 422, 'insufficient_funds');
        $this->useBooks();
        $this->assertSame(0, JournalEntry::where('entry_type', 'payment')->count(), 'nothing saved');
    }

    public function test_t_c_pu_r_006_above_the_accountant_limit_it_waits_and_posts_when_approved(): void
    {
        $bill = $this->bill('600');
        $this->userWithRole('accountant');
        $accountant = $this->login('accountant@meghna.example');
        $r = $this->req('POST', '/api/v1/supplier-payments', $this->body(['amount' => '60000.00', 'allocations' => [['document_id' => $bill->id, 'amount' => '60000.00']]]), $accountant)
            ->assertStatus(202);
        $r->assertJsonPath('data.status', 'submitted')->assertJsonPath('data.approval.required_permission', 'purchases.approve')->assertJsonPath('data.approval.limit', '50000.00');
        $this->assertSame('500000.00', $this->balance($this->bank->ledger_id), 'nothing posted while it waits');
        $this->assertSame('60000.00', (string) $bill->refresh()->balance_due);

        $this->userWithRole('finance');
        $this->req('POST', "/api/v1/approvals/{$r->json('data.approval.id')}/approve", [], $this->login('finance@meghna.example'))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame('440000.00', $this->balance($this->bank->ledger_id));
        $this->assertSame(['0.00', 'paid'], [(string) $bill->refresh()->balance_due, $bill->payment_status], 'settled oldest first on approval (BR-020)');
        $this->req('GET', "/api/v1/supplier-payments/{$r->json('data.id')}")->assertJsonPath('data.status', 'posted')->assertJsonPath('data.payment.unapplied_amount', '0.00');
    }

    public function test_validation(): void
    {
        $customer = $this->customer();
        $this->assertProblem($this->req('POST', '/api/v1/supplier-payments', $this->body(['party_id' => $customer->id])), 422, 'validation_failed')
            ->assertJsonPath('errors.party_id.0', 'Choose one of your suppliers');
        $this->assertProblem($this->req('POST', '/api/v1/supplier-payments', $this->body(['method' => 'cheque'])), 422, 'validation_failed');
        $bill = $this->bill('10');
        $this->assertProblem($this->req('POST', '/api/v1/supplier-payments', $this->body(['amount' => '500.00', 'allocations' => [['document_id' => $bill->id, 'amount' => '800.00']]])),
            422, 'allocation_exceeds_balance');
        $this->useBooks();
        $this->bank->update(['use_for_payments' => false]);
        $this->assertProblem($this->req('POST', '/api/v1/supplier-payments', $this->body()), 422, 'validation_failed')
            ->assertJsonPath('errors.money_account_id.0', 'Sonali Bank ••4521 is not used for payments');
        $this->assertProblem($this->req('POST', '/api/v1/supplier-payments', $this->body(['exchange_rate' => '110.5'])), 422, 'validation_failed');
    }

    public function test_b_r_024_cancelling_a_paid_bill_turns_the_payment_into_an_advance(): void
    {
        $bill = $this->bill('40');
        $p = $this->req('POST', '/api/v1/supplier-payments', $this->body(['amount' => '4000.00', 'allocations' => [['document_id' => $bill->id, 'amount' => '4000.00']]]))->json('data');
        $this->useBooks();
        app(PurchaseBillCancellationService::class)->cancel($bill->refresh(), 'entered_by_mistake');
        $this->assertSame('cancelled', $bill->refresh()->status);
        $this->assertSame(1, DB::table('bill_allocations')->whereNotNull('reversed_at')->count());
        $this->req('GET', "/api/v1/supplier-payments/{$p['id']}")->assertJsonPath('data.payment.unapplied_amount', '4000.00')->assertJsonPath('data.payment.allocations', []);
        $this->assertSame('4000.00', $this->balance($this->bengal->ledger_id), 'the supplier now owes us the payment back as an advance');
    }

    public function test_list_show_pdf_permissions_and_isolation(): void
    {
        $this->req('POST', '/api/v1/supplier-payments', $this->body(['amount' => '1000.00']))->assertCreated();
        $cash = DB::table('money_accounts')->where('account_type', 'bank')->value('id');
        $list = $this->req('GET', '/api/v1/supplier-payments?money_account_id='.$cash)->assertOk();
        $this->assertSame(1, $list->json('meta.total'));
        $id = $list->json('data.0.id');
        $this->assertSame(1, $this->req('GET', '/api/v1/supplier-payments?status=posted&q=Bengal')->json('meta.total'));
        $this->assertSame(0, $this->req('GET', '/api/v1/supplier-payments?status=submitted')->json('meta.total'));
        $this->assertProblem($this->req('GET', '/api/v1/supplier-payments?colour=red'), 422, 'validation_failed');
        $pdf = $this->req('GET', "/api/v1/supplier-payments/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->useBooks();
        $journal = JournalEntry::where('entry_type', 'opening_balance')->value('id');
        $this->assertProblem($this->req('GET', "/api/v1/supplier-payments/{$journal}"), 404, 'not_found');
        $this->userWithRole('sales');
        $this->assertProblem($this->req('POST', '/api/v1/supplier-payments', $this->body(), $this->login('sales@meghna.example')), 403, 'permission_denied');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $this->assertProblem($this->api('GET', "/api/v1/supplier-payments/{$id}", $this->login('padma@agro.example'), $other->id), 404, 'not_found');
    }
}
