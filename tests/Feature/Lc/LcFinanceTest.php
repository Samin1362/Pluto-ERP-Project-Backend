<?php

namespace Tests\Feature\Lc;

use App\Models\Party;
use App\Models\Product;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * LC finance inside the LC portal (P16.4; 06 §12.11–12.13; BR-066–BR-068; TC-LC-014..016), worked to the taka.
 * The LC is US$ 38,400 at planning rate 121.50 (৳ 46,65,600 planned), margin 20 % = ৳ 9,33,120. Paid at 122.10 =
 * ৳ 46,88,640: margin ৳ 9,33,120, the bank's loan ৳ 37,55,520 (Dr supplier / Cr 1403 / Cr 2303).
 * Loan (PAD at 9 %, actual/360) from S = today − 40; converted to LTR at 10 % on today − 25:
 *  - interest to the conversion, 15 days at 9 %: 37,55,520 × 9 × 15 / 36,000 = ৳ 14,083.20 (accrued: Dr 6601 / Cr 2304);
 *  - part repayment today − 10 of ৳ 17,55,520: interest 15 days at 10 % = ৳ 15,648.00 + accrued 14,083.20 → bank ৳ 17,85,251.20;
 *  - month-end accrual through today − 5 on ৳ 20,00,000: 6 days at 10 % = ৳ 3,333.33;
 *  - final repayment today: 4 days = ৳ 2,222.22 + accrued 3,333.33 → bank ৳ 20,05,555.55. Interest in all ৳ 35,286.75.
 */
class LcFinanceTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private Party $straits;

    private Warehouse $wh;

    private Product $oil;

    private array $bank;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $this->straits = $this->supplier('Straits Edible Oils', '01711000801');
        $this->wh = $this->warehouse();
        $this->oil = $this->product('Crude palm oil', 'kg', '300.00');
        $this->bank = $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012345678', 'opening_balance' => '10000000.00'])->assertCreated()->json('data');
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

    private function day(int $offset): string
    {
        return CarbonImmutable::parse($this->today)->addDays($offset)->toDateString();
    }

    private function ledger(string $code): int
    {
        return (int) DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $code)->value('id');
    }

    private function balance(int $ledger, ?int $lc = null, ?string $asOf = null): string
    {
        return (string) DB::table('journal_lines')->where('ledger_id', $ledger)->where('is_posted', 1)->when($lc, fn ($q) => $q->where('letter_of_credit_id', $lc))
            ->when($asOf, fn ($q) => $q->where('entry_date', '<=', $asOf))->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) b')->value('b');
    }

    /** A draft LC with the bank and expiry, submitted to the bank. */
    private function submittedLc(array $extra = []): TestResponse
    {
        $id = $this->req('POST', '/api/v1/letters-of-credit', $extra + [
            'party_id' => $this->straits->id, 'goods_description' => '20,000 kg crude palm oil', 'quantity' => '20000', 'quantity_unit' => 'kg', 'tenor' => 'sight',
            'incoterm' => 'CFR Chattogram', 'currency_code' => 'USD', 'amount' => '38400.00', 'tolerance_pct' => '5', 'planning_rate' => '121.50', 'margin_pct' => '20',
            'margin_account_id' => $this->bank['id'], 'partial_shipments' => true, 'issuing_bank_account_id' => $this->bank['id'], 'expiry_date' => $this->day(60),
        ])->assertCreated()->json('data.id');

        return $this->req('POST', "/api/v1/letters-of-credit/{$id}/transition", ['action' => 'submit'], ['If-Match' => 'W/"1"']);
    }

    private function openedLc(array $extra = []): array
    {
        $lc = $this->submittedLc($extra)->assertOk()->json('data');
        $this->req('POST', "/api/v1/letters-of-credit/{$lc['id']}/transition", ['action' => 'mark_opened', 'bank_reference' => 'SBL/LC/2026/'.$lc['id'], 'date' => $this->today],
            ['If-Match' => "W/\"{$lc['version']}\""])->assertOk();

        return $this->req('GET', "/api/v1/letters-of-credit/{$lc['id']}")->json('data');
    }

    private function facility(array $in): array
    {
        return $this->req('POST', '/api/v1/bank-facilities', ['money_account_id' => $this->bank['id']] + $in)->assertCreated()->json('data');
    }

    private function codes(array $flags): array
    {
        return array_column($flags, 'code');
    }

    public function test_t_c_lc_014_bank_limits_warn_then_block(): void
    {
        $f = $this->facility(['lc_limit' => '4000000.00', 'loan_limit' => '3000000.00', 'limit_action' => 'warn', 'interest_rate_pct' => '9', 'day_count' => '360', 'loan_tenor_days' => 90]);
        $this->assertSame(['4000000.00', '0.00', '4000000.00', 'warn', 1], [$f['lc_limit'], $f['lc_utilised'], $f['lc_available'], $f['limit_action'], $f['version']]);
        $this->assertProblem($this->req('POST', '/api/v1/bank-facilities', ['money_account_id' => $this->bank['id']]), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['money_account_id']]);

        // warn: the LC (৳ 46,65,600 planned) is submitted and carries LC_LIMIT_EXCEEDED
        $lc = $this->openedLc();
        $this->assertContains('LC_LIMIT_EXCEEDED', $this->codes($lc['flags']));
        $f = $this->req('GET', '/api/v1/bank-facilities')->assertOk()->json('data.0');
        $this->assertSame(['4665600.00', '-665600.00', 1], [$f['lc_utilised'], $f['lc_available'], $f['open_lcs']]);

        // block: a second LC cannot be submitted; the first stays
        $this->req('PUT', "/api/v1/bank-facilities/{$f['id']}", ['money_account_id' => $this->bank['id'], 'lc_limit' => '4000000.00', 'loan_limit' => '3000000.00',
            'limit_action' => 'block'], ['If-Match' => 'W/"1"'])->assertOk()->assertJsonPath('data.limit_action', 'block')->assertJsonPath('data.version', 2);
        $this->assertProblem($this->req('PUT', "/api/v1/bank-facilities/{$f['id']}", ['money_account_id' => $this->bank['id']], ['If-Match' => 'W/"1"']), 409, 'version_conflict');
        $this->assertProblem($this->submittedLc(), 422, 'lc_limit_exceeded')->assertJsonPath('would_be', '9331200.00');

        // loan limit (block): ৳ 46,88,640 − margin 9,33,120 = ৳ 37,55,520 > ৳ 30,00,000
        $this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $lc['id'], 'charge_type' => 'margin_deposit', 'charge_date' => $this->today, 'amount' => '933120.00',
            'status' => 'paid', 'paid_from_account_id' => $this->bank['id']])->assertCreated();
        $pay = ['payment_date' => $this->today, 'amount_foreign' => '38400.00', 'bank_rate' => '122.10', 'money_account_id' => $this->bank['id'], 'margin_applied' => '933120.00', 'financed_by' => 'ltr'];
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$lc['id']}/payments", $pay), 422, 'loan_limit_exceeded')->assertJsonPath('would_be', '3755520.00');
        $this->assertSame(0, DB::table('lc_payments')->count(), 'nothing is kept');

        // back to warn: the loan opens and the LC shows LOAN_LIMIT_EXCEEDED
        $this->req('PUT', "/api/v1/bank-facilities/{$f['id']}", ['money_account_id' => $this->bank['id'], 'lc_limit' => '4000000.00', 'loan_limit' => '3000000.00',
            'limit_action' => 'warn'], ['If-Match' => 'W/"2"'])->assertOk();
        $this->req('POST', "/api/v1/letters-of-credit/{$lc['id']}/payments", $pay)->assertCreated()->assertJsonPath('data.financed_by', 'ltr');
        $this->assertContains('LOAN_LIMIT_EXCEEDED', $this->codes($this->req('GET', "/api/v1/letters-of-credit/{$lc['id']}")->json('data.flags')));
        $f = $this->req('GET', '/api/v1/bank-facilities')->json('data.0');
        $this->assertSame(['3755520.00', '-755520.00', 1, '0.00'], [$f['loans_outstanding'], $f['loan_available'], $f['open_loans'], $f['lc_utilised']]);
        $exposure = $this->req('GET', '/api/v1/reports/lc/exposure')->assertOk();
        $exposure->assertJsonPath('data.rows.0.loans_outstanding', '3755520.00')->assertJsonPath('data.totals.loans_outstanding', '3755520.00');

        $this->userWithRole('viewer');
        $this->assertProblem($this->req('POST', '/api/v1/bank-facilities', ['money_account_id' => $this->bank['id']], token: $this->login('viewer@meghna.example')), 403, 'permission_denied');
        $this->assertBooksAreConsistent();
    }

    public function test_t_c_lc_015_financed_payment_loan_interest_accrual_repayments_and_reversal(): void
    {
        $this->facility(['interest_rate_pct' => '9', 'day_count' => '360', 'loan_tenor_days' => 90]);
        $lc = $this->openedLc();
        $id = $lc['id'];
        $this->req('POST', '/api/v1/lc-charges', ['letter_of_credit_id' => $id, 'charge_type' => 'margin_deposit', 'charge_date' => $this->day(-40), 'amount' => '933120.00',
            'status' => 'paid', 'paid_from_account_id' => $this->bank['id']])->assertCreated();
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$id}/payments", ['payment_date' => $this->day(-40), 'amount_foreign' => '38400.00', 'bank_rate' => '122.10',
            'money_account_id' => $this->bank['id'], 'loan' => ['tenor_days' => 90]]), 422, 'validation_failed');

        // financed payment: Dr supplier 46,88,640 / Cr 1403 9,33,120 / Cr 2303 37,55,520; the bank account is not touched
        $bankBefore = $this->balance((int) $this->bank['ledger_id']);
        $p = $this->req('POST', "/api/v1/letters-of-credit/{$id}/payments", ['payment_date' => $this->day(-40), 'amount_foreign' => '38400.00', 'bank_rate' => '122.10',
            'money_account_id' => $this->bank['id'], 'margin_applied' => '933120.00', 'financed_by' => 'pad'])->assertCreated();
        $p->assertJsonPath('data.financed_by', 'pad')->assertJsonPath('data.amount_bdt', '4688640.00');
        $lines = DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $p->json('data.journal_entry_id'))->orderBy('l.line_no')
            ->get(['g.code', 'l.debit', 'l.credit'])->map(fn ($r) => [$r->code, $r->debit, $r->credit])->all();
        $this->assertSame([[$this->straits->ledger->code, '4688640.00', '0.00'], ['1403', '0.00', '933120.00'], ['2303', '0.00', '3755520.00']], $lines);
        $this->assertSame($bankBefore, $this->balance((int) $this->bank['ledger_id']));

        $loanId = $p->json('data.lc_loan_id');
        $loan = $this->req('GET', "/api/v1/lc-loans/{$loanId}")->assertOk()->json('data');
        $this->assertSame([$lc['lc_number'].'/L1', 'pad', '3755520.00', '9.0000', '360', $this->day(-40), $this->day(50), 'open'],
            [$loan['loan_number'], $loan['loan_kind'], $loan['principal'], $loan['interest_rate_pct'], $loan['day_count'], $loan['start_date'], $loan['due_date'], $loan['status']]);

        // PAD → LTR on today − 25 at 10 % for 180 days: 15 days at 9 % accrued first = ৳ 14,083.20
        $this->assertProblem($this->req('POST', "/api/v1/lc-loans/{$loanId}/convert", ['converted_on' => $this->day(-25), 'interest_rate_pct' => '10', 'tenor_days' => 180],
            ['If-Match' => 'W/"9"']), 409, 'version_conflict');
        $this->req('POST', "/api/v1/lc-loans/{$loanId}/convert", ['converted_on' => $this->day(-25), 'interest_rate_pct' => '10', 'tenor_days' => 180], ['If-Match' => "W/\"{$loan['version']}\""])
            ->assertOk()->assertJsonPath('data.loan_kind', 'ltr')->assertJsonPath('data.due_date', $this->day(155))->assertJsonPath('data.accrued_unpaid', '14083.20')
            ->assertJsonPath('data.interest_from', $this->day(-25));
        $this->assertSame('-14083.20', $this->balance($this->ledger('2304')));

        // the quote at today − 10: 15 days at 10 % = ৳ 15,648.00 + accrued 14,083.20
        $this->req('GET', "/api/v1/lc-loans/{$loanId}/interest?as_of=".$this->day(-10))->assertOk()->assertJsonPath('data.days', 15)
            ->assertJsonPath('data.interest', '15648.00')->assertJsonPath('data.accrued_unpaid', '14083.20')->assertJsonPath('data.total_interest', '29731.20');

        // part repayment: Dr 2303 17,55,520 / Dr 2304 14,083.20 / Dr 6601 15,648.00 / Cr bank 17,85,251.20
        $this->assertProblem($this->req('POST', "/api/v1/lc-loans/{$loanId}/repayments", ['repayment_date' => $this->day(-30), 'principal_amount' => '1.00',
            'money_account_id' => $this->bank['id']]), 422, 'validation_failed');
        $r1 = $this->req('POST', "/api/v1/lc-loans/{$loanId}/repayments", ['repayment_date' => $this->day(-10), 'principal_amount' => '1755520.00', 'money_account_id' => $this->bank['id'],
            'reference' => 'SBL-LTR-1'])->assertCreated();
        $r1->assertJsonPath('data.outstanding_principal', '2000000.00')->assertJsonPath('data.repayments.0.interest_amount', '15648.00')
            ->assertJsonPath('data.repayments.0.accrued_cleared', '14083.20')->assertJsonPath('data.repayments.0.interest_days', 15)->assertJsonPath('data.accrued_unpaid', '0.00');
        $entry = $r1->json('data.repayments.0.journal_entry_id');
        $lines = DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $entry)->orderBy('l.line_no')
            ->get(['g.code', 'l.debit', 'l.credit'])->map(fn ($r) => [$r->code, $r->debit, $r->credit])->all();
        $bankCode = DB::table('ledgers')->where('id', $this->bank['ledger_id'])->value('code');
        $this->assertSame([['2303', '1755520.00', '0.00'], ['2304', '14083.20', '0.00'], ['6601', '15648.00', '0.00'], [$bankCode, '0.00', '1785251.20']], $lines);

        // month-end accrual through today − 5: 6 days at 10 % on ৳ 20,00,000 = ৳ 3,333.33 (idempotent per date)
        $this->artisan('pluto:loans:accrue', ['--date' => $this->day(-5)])->expectsOutputToContain('1 accrual(s)')->assertSuccessful();
        $this->artisan('pluto:loans:accrue', ['--date' => $this->day(-5)])->expectsOutputToContain('0 accrual(s)')->assertSuccessful();
        $this->assertSame('-3333.33', $this->balance($this->ledger('2304')));

        // too much principal, then the final repayment: 4 days = ৳ 2,222.22 + accrued 3,333.33
        $this->assertProblem($this->req('POST', "/api/v1/lc-loans/{$loanId}/repayments", ['repayment_date' => $this->today, 'principal_amount' => '2000000.01',
            'money_account_id' => $this->bank['id']]), 422, 'repayment_exceeds_outstanding');
        $final = ['repayment_date' => $this->today, 'principal_amount' => '2000000.00', 'money_account_id' => $this->bank['id']];
        $r2 = $this->req('POST', "/api/v1/lc-loans/{$loanId}/repayments", $final)->assertCreated();
        $r2->assertJsonPath('data.status', 'repaid')->assertJsonPath('data.outstanding_principal', '0.00')->assertJsonPath('data.repayments.1.interest_amount', '2222.22')
            ->assertJsonPath('data.repayments.1.accrued_cleared', '3333.33')->assertJsonPath('data.interest_paid', '35286.75');
        $this->assertSame(['0.00', '0.00', '35286.75'], [$this->balance($this->ledger('2303')), $this->balance($this->ledger('2304')), $this->balance($this->ledger('6601'))]);

        // the LC payment cannot be reversed while the loan has repayments
        $this->assertProblem($this->req('POST', "/api/v1/lc-payments/{$p->json('data.id')}/reverse", ['reason' => 'x']), 409, 'invalid_transition');

        // reverse the last repayment: principal, the accrual and the interest date come back; only the latest can be reversed
        $this->assertProblem($this->req('POST', "/api/v1/lc-loan-repayments/{$r1->json('data.repayments.0.id')}/reverse", ['reason' => 'x']), 409, 'invalid_transition');
        $rev = $this->req('POST', "/api/v1/lc-loan-repayments/{$r2->json('data.repayments.1.id')}/reverse", ['reason' => 'Bank debited the wrong account'])->assertOk();
        $rev->assertJsonPath('data.status', 'open')->assertJsonPath('data.outstanding_principal', '2000000.00')->assertJsonPath('data.accrued_unpaid', '3333.33')
            ->assertJsonPath('data.interest_from', $this->day(-4));
        $this->assertSame(['-2000000.00', '-3333.33'], [$this->balance($this->ledger('2303')), $this->balance($this->ledger('2304'))]);
        $this->req('POST', "/api/v1/lc-loans/{$loanId}/repayments", $final)->assertCreated()->assertJsonPath('data.status', 'repaid');
        $this->assertSame('35286.75', $this->balance($this->ledger('6601')));

        // lists and reports in the LC portal
        $this->req('GET', '/api/v1/lc-loans?status=repaid')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.loan_number', $lc['lc_number'].'/L1');
        $this->req('GET', '/api/v1/lc-loans?status=open')->assertOk()->assertJsonPath('meta.total', 0);
        $this->req('GET', '/api/v1/reports/lc/loans?segment=all')->assertOk()->assertJsonPath('data.totals.principal', '3755520.00')->assertJsonPath('data.totals.outstanding_principal', '0.00');
        $this->req('GET', '/api/v1/lc/overview')->assertOk()->assertJsonPath('data.totals.loans_open', 0)->assertJsonPath('data.totals.loans_outstanding', '0.00');
        $this->assertProblem($this->req('GET', '/api/v1/lc-loans?colour=red'), 422, 'validation_failed');
        $this->assertBooksAreConsistent();
        $this->artisan('pluto:integrity')->assertSuccessful();

        // another company cannot see the loan
        $this->provisionedCompany('Padma Foods Ltd.', 'owner@padma.example');
        $this->token = $this->login('owner@padma.example');
        $this->assertProblem($this->req('GET', "/api/v1/lc-loans/{$loanId}"), 404, 'not_found');
    }

    public function test_financed_payment_reversal_takes_its_loan_and_accruals_with_it(): void
    {
        $this->facility(['interest_rate_pct' => '12', 'day_count' => '365', 'loan_tenor_days' => 120]);
        $lc = $this->openedLc();
        $p = $this->req('POST', "/api/v1/letters-of-credit/{$lc['id']}/payments", ['payment_date' => $this->day(-20), 'amount_foreign' => '10000.00', 'bank_rate' => '122.00',
            'money_account_id' => $this->bank['id'], 'financed_by' => 'ltr', 'loan' => ['interest_rate_pct' => '11', 'tenor_days' => 60]])->assertCreated()->json('data');
        $loan = $this->req('GET', "/api/v1/lc-loans/{$p['lc_loan_id']}")->json('data');
        $this->assertSame(['ltr', '1220000.00', '11.0000', '365', $this->day(40)], [$loan['loan_kind'], $loan['principal'], $loan['interest_rate_pct'], $loan['day_count'], $loan['due_date']]);
        // 1,220,000 × 11 × 10 / 36,500 = 3,676.71
        $this->artisan('pluto:loans:accrue', ['--date' => $this->day(-11)])->assertSuccessful();
        $this->assertSame('-3676.71', $this->balance($this->ledger('2304')));
        $this->req('POST', "/api/v1/lc-payments/{$p['id']}/reverse", ['reason' => 'Booked twice'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->req('GET', "/api/v1/lc-loans/{$p['lc_loan_id']}")->assertJsonPath('data.status', 'reversed');
        $this->assertSame(['0.00', '0.00', '0.00'], [$this->balance($this->ledger('2303')), $this->balance($this->ledger('2304')), $this->balance($this->ledger('6601'))]);

        // an acceptance is not financed (the bank pays at maturity)
        $usance = $this->openedLc(['tenor' => 'usance', 'usance_days' => 90]);
        $this->assertProblem($this->req('POST', "/api/v1/letters-of-credit/{$usance['id']}/payments", ['payment_kind' => 'usance_acceptance', 'payment_date' => $this->today,
            'amount_foreign' => '100.00', 'bank_rate' => '122', 'money_account_id' => $this->bank['id'], 'financed_by' => 'ltr']), 422, 'validation_failed');
        $this->assertBooksAreConsistent();
    }

    public function test_t_c_lc_016_fx_revaluation_of_an_open_bill_and_an_acceptance(): void
    {
        // an unpaid import bill: US$ 38,400 at 121.50 = ৳ 46,65,600
        $sight = $this->openedLc();
        $bill = $this->req('POST', '/api/v1/purchase-bills', ['party_id' => $this->straits->id, 'supplier_bill_no' => 'SEO-CI-90', 'bill_date' => $this->day(-3), 'warehouse_id' => $this->wh->id,
            'letter_of_credit_id' => $sight['id'], 'lines' => [['product_id' => $this->oil->id, 'quantity' => '20000', 'unit_cost' => '1.92']]])->assertCreated()->json('data');
        $this->req('POST', "/api/v1/purchase-bills/{$bill['id']}/post")->assertOk()->assertJsonPath('data.total', '4665600.00');
        // an open acceptance: US$ 38,400 at 122.00 = ৳ 46,84,800
        $usance = $this->openedLc(['tenor' => 'usance', 'usance_days' => 90]);
        $this->req('POST', "/api/v1/letters-of-credit/{$usance['id']}/payments", ['payment_kind' => 'usance_acceptance', 'payment_date' => $this->day(-3), 'amount_foreign' => '38400.00',
            'bank_rate' => '122.00', 'money_account_id' => $this->bank['id']])->assertCreated();

        $this->assertProblem($this->req('POST', '/api/v1/fx-revaluations/preview', ['revaluation_date' => $this->day(-1), 'rates' => [['currency_code' => 'EUR', 'rate' => '135']]]),
            422, 'nothing_to_revalue');
        $this->assertProblem($this->req('POST', '/api/v1/fx-revaluations/preview', ['revaluation_date' => $this->day(1), 'rates' => [['currency_code' => 'USD', 'rate' => '123']]]),
            422, 'validation_failed');

        // at 123.00: bill 38,400 × 123 = 47,23,200 → loss 57,600; acceptance 47,23,200 − 46,84,800 → loss 38,400
        $in = ['revaluation_date' => $this->day(-1), 'rates' => [['currency_code' => 'USD', 'rate' => '123.00']], 'note' => 'Month end'];
        $pre = $this->req('POST', '/api/v1/fx-revaluations/preview', $in)->assertOk();
        $pre->assertJsonPath('data.status', 'preview')->assertJsonPath('data.total_difference', '96000.00')->assertJsonPath('data.reversal_date', $this->today)
            ->assertJsonPath('data.lines.0.source', 'purchase_bill')->assertJsonPath('data.lines.0.foreign_outstanding', '38400.00')->assertJsonPath('data.lines.0.difference', '57600.00')
            ->assertJsonPath('data.lines.1.source', 'lc_acceptance')->assertJsonPath('data.lines.1.book_rate', '122.000000')->assertJsonPath('data.lines.1.difference', '38400.00');
        $this->assertSame(0, DB::table('fx_revaluations')->count(), 'a preview keeps nothing');

        $rv = $this->req('POST', '/api/v1/fx-revaluations', $in)->assertCreated()->json('data');
        $this->assertSame(['posted', '96000.00', 2], [$rv['status'], $rv['total_difference'], count($rv['lines'])]);
        $this->assertSame('96000.00', $this->balance($this->ledger('6603'), asOf: $this->day(-1)), 'unrealised loss on the revaluation date');
        $this->assertSame('-4723200.00', $this->balance($this->ledger('2302'), asOf: $this->day(-1)));
        $this->assertSame(['0.00', '-4684800.00'], [$this->balance($this->ledger('6603')), $this->balance($this->ledger('2302'))], 'the next-day mirror undoes it');
        $this->assertSame('-4684800.00', $this->balance($this->ledger('2302'), $usance['id']), "the LC's own 2302 stays at the acceptance rate");
        // its supplier line never settles a bill
        $this->req('GET', "/api/v1/purchase-bills/{$bill['id']}")->assertJsonPath('data.balance_due', '4665600.00');

        $this->req('GET', '/api/v1/fx-revaluations')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.rates.USD', '123.00');
        $this->req('POST', "/api/v1/fx-revaluations/{$rv['id']}/reverse", ['reason' => 'Wrong closing rate'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame('0.00', $this->balance($this->ledger('6603'), asOf: $this->day(-1)));
        $this->assertProblem($this->req('POST', "/api/v1/fx-revaluations/{$rv['id']}/reverse", ['reason' => 'again']), 409, 'already_reversed');

        $this->userWithRole('viewer');
        $this->assertProblem($this->req('POST', '/api/v1/fx-revaluations', $in, token: $this->login('viewer@meghna.example')), 403, 'permission_denied');
        $this->assertBooksAreConsistent();
        $this->artisan('pluto:integrity')->assertSuccessful();
    }
}
