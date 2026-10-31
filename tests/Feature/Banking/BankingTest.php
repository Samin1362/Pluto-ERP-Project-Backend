<?php

namespace Tests\Feature\Banking;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Cash and bank (G1, G6–G9, G11–G13; 06 §13, §17; BR-005, BR-031, BR-065; TC-BNK-001, TC-BNK-002; backlog P12.1-BE-01/02):
 * money received, paid and moved through vouchers; the overview, the money transactions and an account's book.
 */
class BankingTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private array $cash;

    private array $sonali;

    private array $dbbl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->cash = $this->account(['account_type' => 'cash', 'opening_balance' => '20000.00']);
        $this->sonali = $this->account(['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.', 'account_number' => '0012345678',
            'opening_balance' => '250000.00']);
        $this->dbbl = $this->account(['account_type' => 'bank', 'bank_name' => 'Dutch-Bangla Bank', 'account_name' => 'Meghna Traders Ltd.', 'account_number' => '1051100045219',
            'opening_balance' => '0.00']);
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

    private function account(array $in): array
    {
        return $this->req('POST', '/api/v1/money-accounts', $in)->assertCreated()->json('data');
    }

    private function ledger(string $code): int
    {
        return (int) DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $code)->value('id');
    }

    private function balance(array $account): string
    {
        return (string) DB::table('journal_lines')->where('ledger_id', $account['ledger_id'])->where('is_posted', 1)->selectRaw('SUM(debit) - SUM(credit) b')->value('b');
    }

    private function voucher(string $type, array $payment, array $lines)
    {
        return $this->req('POST', '/api/v1/vouchers', ['entry_type' => $type, 'action' => 'post', 'date' => $this->today(), 'narration' => "{$type} test",
            'payment' => $payment, 'lines' => $lines]);
    }

    public function test_g6_g7_money_received_and_paid_post_through_vouchers(): void
    {
        $this->voucher('receipt', ['money_account_id' => $this->cash['id'], 'method' => 'cash'], [['ledger_id' => $this->ledger('3101'), 'amount' => '15000.00',
            'line_narration' => 'Owner put in cash']])->assertCreated();
        $this->voucher('payment', ['money_account_id' => $this->sonali['id'], 'method' => 'cheque', 'cheque_number' => '448812', 'payee_text' => 'Landlord'],
            [['ledger_id' => $this->ledger('6101'), 'amount' => '25000.00']])->assertCreated();
        $this->assertSame(['35000.00', '225000.00'], [$this->balance($this->cash), $this->balance($this->sonali)]);
    }

    public function test_tc_bnk_001_bank_transfer_with_a_bank_charge(): void
    {
        $r = $this->voucher('contra', ['money_account_id' => $this->sonali['id'], 'counter_money_account_id' => $this->dbbl['id'], 'method' => 'beftn', 'bank_charge' => '50.00'],
            [['ledger_id' => $this->dbbl['ledger_id'], 'amount' => '100000.00']])->assertCreated();
        $lines = DB::table('journal_lines as l')->join('ledgers as g', 'g.id', '=', 'l.ledger_id')->where('l.journal_entry_id', $r->json('data.id'))->orderBy('l.line_no')
            ->get(['g.code', 'l.debit', 'l.credit'])->map(fn ($l) => [$l->code, (string) $l->debit, (string) $l->credit])->all();
        $this->assertEqualsCanonicalizing([[DB::table('ledgers')->where('id', $this->dbbl['ledger_id'])->value('code'), '100000.00', '0.00'], ['6702', '50.00', '0.00'],
            [DB::table('ledgers')->where('id', $this->sonali['ledger_id'])->value('code'), '0.00', '100050.00']], $lines);
        $this->assertSame(['149950.00', '100000.00'], [$this->balance($this->sonali), $this->balance($this->dbbl)]);
    }

    public function test_tc_bnk_002_cash_cannot_be_moved_above_its_balance(): void
    {
        $e = $this->assertProblem($this->voucher('contra', ['money_account_id' => $this->cash['id'], 'counter_money_account_id' => $this->sonali['id'], 'method' => 'cash'],
            [['ledger_id' => $this->sonali['ledger_id'], 'amount' => '20000.01']]), 422, 'insufficient_funds');
        $this->assertStringContainsString('BR-031', $e->json('detail'));
        $this->assertSame('20000.00', $this->balance($this->cash));
    }

    public function test_overview_transactions_and_book(): void
    {
        $this->voucher('receipt', ['money_account_id' => $this->cash['id'], 'method' => 'cash'], [['ledger_id' => $this->ledger('3101'), 'amount' => '5000.00']])->assertCreated();
        $this->voucher('payment', ['money_account_id' => $this->sonali['id'], 'method' => 'bank_transfer', 'payee_text' => 'DESCO'],
            [['ledger_id' => $this->ledger('6101'), 'amount' => '3000.00', 'line_narration' => 'Electricity']])->assertCreated();
        $this->voucher('contra', ['money_account_id' => $this->cash['id'], 'counter_money_account_id' => $this->sonali['id'], 'method' => 'cash'],
            [['ledger_id' => $this->sonali['ledger_id'], 'amount' => '10000.00']])->assertCreated();
        // a draft never counts (BR-005)
        $this->req('POST', '/api/v1/vouchers', ['entry_type' => 'payment', 'action' => 'draft', 'date' => $this->today(),
            'payment' => ['money_account_id' => $this->cash['id'], 'method' => 'cash'], 'lines' => [['ledger_id' => $this->ledger('6101'), 'amount' => '999.00']]])->assertCreated();

        $o = $this->req('GET', '/api/v1/banking/overview')->assertOk();
        $o->assertJsonPath('data.totals.total', '272000.00')->assertJsonPath('data.totals.cash', '15000.00')->assertJsonPath('data.totals.bank', '257000.00')
            ->assertJsonPath('data.totals.accounts', 3)->assertJsonPath('data.rows.0.account_type', 'cash')->assertJsonPath('data.rows.2.account_number', '•••5678');
        $this->assertCount(30, $o->json('data.meta.series'));
        $o->assertJsonPath('data.meta.series.29.balance', '272000.00');

        $t = $this->req('GET', '/api/v1/money-transactions?per_page=50')->assertOk();
        $this->assertSame(['transfer', 'transfer', 'out', 'in'], array_slice(array_column($t->json('data'), 'direction'), 0, 4));
        $this->assertSame(['DESCO'], array_column($this->req('GET', '/api/v1/money-transactions?direction=out')->json('data'), 'particulars'));
        $this->req('GET', "/api/v1/money-transactions?money_account_id={$this->cash['id']}&direction=in")->assertJsonPath('meta.total', 2); // the opening balance and the receipt

        $b = $this->req('GET', "/api/v1/money-accounts/{$this->sonali['id']}/book")->assertOk();
        $b->assertJsonPath('data.closing', '257000.00')->assertJsonPath('data.closing_side', 'dr');
        $lines = $b->json('data.lines');
        $this->assertSame('257000.00', end($lines)['balance']);
        $this->assertCount(1, $this->req('GET', "/api/v1/money-accounts/{$this->sonali['id']}/book?view=out")->json('data.lines'));
        $this->assertProblem($this->req('GET', "/api/v1/money-accounts/{$this->sonali['id']}/book?view=x"), 422, 'validation_failed');

        $this->userWithRole('sales', 'sales@meghna.example');
        $this->assertProblem($this->req('GET', '/api/v1/banking/overview', [], $this->login('sales@meghna.example')), 403, 'permission_denied');
    }
}
