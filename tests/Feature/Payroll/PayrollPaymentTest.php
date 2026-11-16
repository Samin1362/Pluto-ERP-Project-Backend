<?php

namespace Tests\Feature\Payroll;

use App\Domain\Banking\MoneyAccountService;
use App\Models\MoneyAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Paying salaries (P4; PEP-522): pay all or some, never more than due, funds, part paid / paid, reverse, cancel blocked, permissions. */
class PayrollPaymentTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private int $run;

    private array $lines;

    private MoneyAccount $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $month = CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->subMonthNoOverflow();
        foreach ([['Rahim Uddin', '20000'], ['Karim Hossain', '12000']] as [$name, $basic]) {
            $this->req('POST', '/api/v1/employees', ['name' => $name, 'joining_date' => '2025-01-01', 'basic_salary' => $basic])->assertCreated();
        }
        $r = $this->req('POST', '/api/v1/payroll-runs', ['month' => $month->format('Y-m')])->assertCreated();
        $this->run = $r->json('data.id');
        $this->lines = $r->json('data.lines');
        $this->useBooks();
        $this->cash = app(MoneyAccountService::class)->create(['account_type' => 'cash', 'opening_balance' => '25000.00', 'opening_date' => $month->toDateString()]);
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null, array $headers = [])
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, ($method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []) + $headers);
        $this->useBooks();

        return $r;
    }

    private function balance(int $ledgerId): string
    {
        return number_format((float) DB::table('journal_lines')->where('ledger_id', $ledgerId)->where('is_posted', true)->sum(DB::raw('debit - credit')), 2, '.', '');
    }

    private function payable(): string
    {
        return $this->balance((int) DB::table('ledgers')->where('company_id', $this->books->id)->where('code', '2201')->value('id'));
    }

    public function test_pay_some_then_the_rest_and_reverse(): void
    {
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $pay = fn (array $body) => $this->req('POST', "/api/v1/payroll-runs/{$this->run}/payments", $body + ['money_account_id' => $this->cash->id, 'paid_on' => $today]);
        $this->assertProblem($pay([]), 409, 'payroll_run_not_approved');
        $this->req('POST', "/api/v1/payroll-runs/{$this->run}/approve")->assertOk()->assertJsonPath('data.payment_status', 'unpaid')->assertJsonPath('data.total_paid', '0.00');
        $this->assertSame('-32000.00', $this->payable());

        // Rahim gets 15,000 now (bKash reference); more than he is owed is refused.
        $this->assertProblem($pay(['lines' => [['payroll_line_id' => $this->lines[0]['id'], 'amount' => '20000.01']]]), 422, 'payroll_overpaid');
        $p1 = $pay(['reference' => 'TRX123', 'lines' => [['payroll_line_id' => $this->lines[0]['id'], 'amount' => '15000']]])->assertCreated();
        $p1->assertJsonPath('data.amount', '15000.00')->assertJsonPath('data.employees_count', 1)->assertJsonPath('data.lines.0.employee_name', 'Rahim Uddin')
            ->assertJsonPath('data.money_account.id', $this->cash->id)->assertJsonPath('data.status', 'posted');
        $this->assertSame(['-17000.00', '10000.00'], [$this->payable(), $this->balance((int) $this->cash->ledger_id)]);
        $this->req('GET', "/api/v1/payroll-runs/{$this->run}")->assertJsonPath('data.payment_status', 'part_paid')->assertJsonPath('data.total_paid', '15000.00')
            ->assertJsonPath('data.lines.0.paid_amount', '15000.00');

        // "Pay everyone still due" is 5,000 + 12,000 = 17,000, more than the 10,000 left in cash.
        $this->assertProblem($pay([]), 422, 'insufficient_funds');
        $bank = app(MoneyAccountService::class)->create(['account_type' => 'bank', 'bank_name' => 'Dutch-Bangla Bank', 'account_number' => '1051', 'opening_balance' => '50000.00',
            'opening_date' => CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->subMonthNoOverflow()->toDateString()]);
        $p2 = $pay(['money_account_id' => $bank->id])->assertCreated()->assertJsonPath('data.amount', '17000.00')->assertJsonPath('data.employees_count', 2);
        $this->req('GET', "/api/v1/payroll-runs/{$this->run}")->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame('0.00', $this->payable());
        $this->assertProblem($pay([]), 422, 'nothing_to_pay');
        $this->assertProblem($this->req('POST', "/api/v1/payroll-runs/{$this->run}/cancel", ['reason' => 'x']), 409, 'payroll_run_not_approved');
        $this->assertBooksAreConsistent();

        // Reverse the first payment: Rahim is due 15,000 again and the cash comes back.
        $this->req('POST', "/api/v1/payroll-payments/{$p1->json('data.id')}/reverse", ['reason' => 'Paid the wrong person'])->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame(['-15000.00', '25000.00'], [$this->payable(), $this->balance((int) $this->cash->ledger_id)]);
        $this->assertProblem($this->req('POST', "/api/v1/payroll-payments/{$p1->json('data.id')}/reverse", ['reason' => 'again']), 409, 'already_reversed');
        $this->req('GET', "/api/v1/payroll-runs/{$this->run}/payments")->assertOk()->assertJsonCount(2, 'data');
        $this->req('GET', "/api/v1/payroll-runs/{$this->run}")->assertJsonPath('data.payment_status', 'part_paid')->assertJsonPath('data.total_paid', '17000.00');
        $this->assertBooksAreConsistent();
        $this->assertNotNull($p2->json('data.voucher_number'));
    }

    public function test_permissions_and_isolation(): void
    {
        $this->req('POST', "/api/v1/payroll-runs/{$this->run}/approve")->assertOk();
        $this->userWithRole('accountant');
        $acc = $this->login('accountant@meghna.example');
        $body = ['money_account_id' => $this->cash->id, 'paid_on' => CarbonImmutable::now('Asia/Dhaka')->toDateString()];
        $this->assertProblem($this->req('POST', "/api/v1/payroll-runs/{$this->run}/payments", $body, $acc), 403, 'permission_denied');
        $this->req('GET', "/api/v1/payroll-runs/{$this->run}/payments", token: $acc)->assertOk();
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/payroll-runs/{$this->run}/payments", $ot, $other->id), 404, 'not_found');
        $this->useBooks();
    }
}
