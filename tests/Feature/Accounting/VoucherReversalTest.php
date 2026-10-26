<?php

namespace Tests\Feature\Accounting;

use App\Domain\Banking\MoneyAccountService;
use App\Domain\Sales\SalesInvoicePostingService;
use App\Domain\Sales\SalesInvoiceService;
use App\Models\AccountingPeriod;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** POST /vouchers/{id}/reverse and GET /vouchers/{id}/pdf (Acc-DlgReverse, H13/H14; BR-003, BR-009; GAP-07; TC-ACC-007/008; backlog P7.1-BE-06). */
class VoucherReversalTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function ledger(string $code): int
    {
        $this->useBooks();

        return (int) Ledger::where('code', $code)->value('id');
    }

    private function balance(int $ledgerId): string
    {
        $this->useBooks();

        return (string) DB::table('journal_lines')->where('ledger_id', $ledgerId)->where('is_posted', 1)->selectRaw('CAST(COALESCE(SUM(debit) - SUM(credit), 0) AS DECIMAL(18,2)) b')->value('b');
    }

    private function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    private function journal(string $action = 'post'): array
    {
        return $this->req('POST', '/api/v1/vouchers', ['entry_type' => 'journal', 'action' => $action, 'date' => $this->today(), 'narration' => 'Depreciation',
            'lines' => [['ledger_id' => $this->ledger('6501'), 'debit' => '5000.00', 'line_narration' => 'Vans'], ['ledger_id' => $this->ledger('1509'), 'credit' => '5000.00']]])
            ->json('data');
    }

    public function test_t_c_ac_c_007_a_reversal_swaps_the_lines_under_a_new_number_once(): void
    {
        $v = $this->journal();
        $r = $this->req('POST', "/api/v1/vouchers/{$v['id']}/reverse", ['reversal_date' => $this->today(), 'reason' => 'Posted twice'])->assertCreated();
        $r->assertJsonPath('data.entry_type', 'reversal')->assertJsonPath('data.status', 'posted')->assertJsonPath('data.reverses_entry_id', $v['id'])
            ->assertJsonPath('data.reference', $v['voucher_number'])->assertJsonPath('data.narration', "Reversal of {$v['voucher_number']}: Posted twice");
        $this->assertMatchesRegularExpression('/^JV-\d{4}-0002$/', $r->json('data.voucher_number'));
        $this->assertSame([['1509', '5000.00', '0.00'], ['6501', '0.00', '5000.00']],
            collect($r->json('data.lines'))->map(fn ($l) => [$l['ledger_code'], $l['debit'], $l['credit']])->sortBy(0)->values()->all());
        $this->assertSame('0.00', $this->balance($this->ledger('6501')));
        $this->req('GET', "/api/v1/vouchers/{$v['id']}")->assertJsonPath('data.status', 'reversed')->assertJsonPath('data.reversed_by_entry_id', $r->json('data.id'));
        $this->assertTrue(AuditLog::where('action', 'reversed')->where('auditable_id', $v['id'])->exists());

        $this->assertProblem($this->req('POST', "/api/v1/vouchers/{$v['id']}/reverse", ['reversal_date' => $this->today(), 'reason' => 'Again']), 409, 'already_reversed');
        $this->assertProblem($this->req('POST', "/api/v1/vouchers/{$r->json('data.id')}/reverse", ['reversal_date' => $this->today(), 'reason' => 'Undo']), 409, 'use_document_cancel');
    }

    public function test_drafts_system_entries_dates_and_locked_periods_are_refused(): void
    {
        $draft = $this->journal('draft');
        $this->assertProblem($this->req('POST', "/api/v1/vouchers/{$draft['id']}/reverse", ['reversal_date' => $this->today(), 'reason' => 'X']), 422, 'validation_failed');
        $this->useBooks();
        app(MoneyAccountService::class)->create(['account_type' => 'cash', 'opening_balance' => '1000.00']);
        $opening = JournalEntry::where('entry_type', 'opening_balance')->value('id');
        $this->assertProblem($this->req('POST', "/api/v1/vouchers/{$opening}/reverse", ['reversal_date' => $this->today(), 'reason' => 'X']), 409, 'use_document_cancel');

        $v = $this->journal();
        $this->assertProblem($this->req('POST', "/api/v1/vouchers/{$v['id']}/reverse", []), 422, 'validation_failed');
        $yesterday = CarbonImmutable::now('Asia/Dhaka')->subDay()->toDateString();
        $this->assertProblem($this->req('POST', "/api/v1/vouchers/{$v['id']}/reverse", ['reversal_date' => $yesterday, 'reason' => 'X']), 422, 'validation_failed');
        $this->useBooks();
        $period = AccountingPeriod::whereDate('start_date', '<=', $this->today())->whereDate('end_date', '>=', $this->today())->firstOrFail();
        $period->forceFill(['status' => 'locked'])->save();
        $this->assertProblem($this->req('POST', "/api/v1/vouchers/{$v['id']}/reverse", ['reversal_date' => $this->today(), 'reason' => 'X']), 422, 'period_locked');
        $this->useBooks();
        $this->assertSame('posted', JournalEntry::findOrFail($v['id'])->status);

        $this->userWithRole('viewer');
        $this->assertProblem($this->req('POST', "/api/v1/vouchers/{$v['id']}/reverse", ['reversal_date' => $this->today(), 'reason' => 'X'], $this->login('viewer@meghna.example')),
            403, 'permission_denied');
    }

    public function test_t_c_ac_c_008_reversing_a_receipt_reopens_the_invoice_it_settled(): void
    {
        $customer = $this->customer();
        $wh = $this->warehouse();
        $trip = $this->product('Delivery trip', 'pcs', '1.00', 'service');
        $this->useBooks();
        $cash = app(MoneyAccountService::class)->create(['account_type' => 'cash']);
        $inv = app(SalesInvoiceService::class)->createDraft(['party_id' => $customer->id, 'invoice_date' => $this->today(), 'warehouse_id' => $wh->id,
            'lines' => [['product_id' => $trip->id, 'quantity' => '8000', 'unit_price' => '1.00']]]);
        $inv = app(SalesInvoicePostingService::class)->post($inv);
        $rv = $this->req('POST', '/api/v1/vouchers', ['entry_type' => 'receipt', 'action' => 'post', 'date' => $this->today(),
            'payment' => ['money_account_id' => $cash->id, 'method' => 'cash'],
            'lines' => [['ledger_id' => $customer->ledger_id, 'amount' => '8000.00', 'allocations' => [['document_id' => $inv->id, 'amount' => '8000.00']]]]])->assertCreated();
        $rv->assertJsonPath('data.payment.allocations.0.document_number', $inv->number)->assertJsonPath('data.payment.unapplied_amount', '0.00');
        $this->useBooks();
        $this->assertSame(['0.00', 'paid'], [(string) $inv->refresh()->balance_due, $inv->payment_status]);

        $this->req('POST', "/api/v1/vouchers/{$rv->json('data.id')}/reverse", ['reversal_date' => $this->today(), 'reason' => 'Cheque bounced'])->assertCreated();
        $this->useBooks();
        $this->assertSame(['8000.00', 'unpaid'], [(string) $inv->refresh()->balance_due, $inv->payment_status]);
        $this->assertSame(1, DB::table('bill_allocations')->whereNotNull('reversed_at')->count());
        $this->assertSame('0.00', $this->balance($cash->ledger_id));
    }

    public function test_the_voucher_pdf(): void
    {
        $v = $this->journal();
        $r = $this->req('GET', "/api/v1/vouchers/{$v['id']}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertStringContainsString("{$v['voucher_number']}.pdf", $r->headers->get('Content-Disposition'));
        $this->userWithRole('sales');
        $this->assertProblem($this->req('GET', "/api/v1/vouchers/{$v['id']}/pdf", token: $this->login('sales@meghna.example')), 403, 'permission_denied');
        $this->assertProblem($this->req('GET', '/api/v1/vouchers/999999/pdf'), 404, 'not_found');
    }
}
