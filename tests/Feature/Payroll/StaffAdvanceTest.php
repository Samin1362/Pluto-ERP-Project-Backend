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

/** Staff advances (P5; PEP-523): give, recover through salary sheets oldest first, cancel undoes, reverse, funds, permissions. */
class StaffAdvanceTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private int $rahim;

    private MoneyAccount $cash;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->month = CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->subMonthNoOverflow();
        $this->rahim = $this->req('POST', '/api/v1/employees', ['name' => 'Rahim Uddin', 'joining_date' => '2025-01-01', 'basic_salary' => '20000'])->assertCreated()->json('data.id');
        $this->useBooks();
        $this->cash = app(MoneyAccountService::class)->create(['account_type' => 'cash', 'opening_balance' => '10000.00', 'opening_date' => $this->month->toDateString()]);
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null, array $headers = [])
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, ($method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []) + $headers);
        $this->useBooks();

        return $r;
    }

    private function advance(string $amount, string $instalment, ?string $on = null)
    {
        return $this->req('POST', '/api/v1/staff-advances', ['employee_id' => $this->rahim, 'money_account_id' => $this->cash->id, 'given_on' => $on ?? $this->month->toDateString(),
            'amount' => $amount, 'instalment' => $instalment, 'reference' => 'Eid shopping']);
    }

    private function staffAdvancesBalance(): string
    {
        $id = DB::table('account_mappings')->where('company_id', $this->books->id)->where('mapping_key', 'staff_advances')->value('ledger_id');

        return number_format((float) DB::table('journal_lines')->where('ledger_id', $id)->where('is_posted', true)->sum(DB::raw('debit - credit')), 2, '.', '');
    }

    public function test_give_recover_through_sheets_and_cancel(): void
    {
        $a = $this->advance('5000', '2000')->assertCreated();
        $a->assertJsonPath('data.amount', '5000.00')->assertJsonPath('data.balance', '5000.00')->assertJsonPath('data.status', 'open')->assertJsonPath('data.employee.name', 'Rahim Uddin');
        $this->assertSame('5000.00', $this->staffAdvancesBalance());
        $this->req('GET', "/api/v1/employees/{$this->rahim}")->assertJsonPath('data.advance_balance', '5000.00');
        $this->assertProblem($this->advance('6000', '1000'), 422, 'insufficient_funds'); // 5,000 left in cash
        $this->assertProblem($this->advance('1000', '2000'), 422, 'validation_failed'); // instalment above the advance

        // The month's sheet takes the 2,000 instalment; it can be changed while draft, but not above what is owed.
        $s = $this->req('POST', '/api/v1/payroll-runs', ['month' => $this->month->format('Y-m')])->assertCreated();
        $s->assertJsonPath('data.lines.0.advance_recovery', '2000.00')->assertJsonPath('data.lines.0.net', '18000.00');
        $line = $s->json('data.lines.0.id');
        $this->assertProblem($this->req('PUT', "/api/v1/payroll-runs/{$s->json('data.id')}", ['lines' => [['id' => $line, 'advance_recovery' => '5000.01']]],
            headers: ['If-Match' => 'W/"'.$s->json('data.version').'"']), 422, 'validation_failed');
        $u = $this->req('PUT', "/api/v1/payroll-runs/{$s->json('data.id')}", ['lines' => [['id' => $line, 'advance_recovery' => '3000']]], headers: ['If-Match' => 'W/"'.$s->json('data.version').'"'])->assertOk();
        $u->assertJsonPath('data.lines.0.net', '17000.00');
        $this->req('POST', "/api/v1/payroll-runs/{$s->json('data.id')}/approve")->assertOk();
        $this->assertSame('2000.00', $this->staffAdvancesBalance(), '3,000 taken back');
        $this->req('GET', '/api/v1/staff-advances')->assertJsonPath('data.0.recovered', '3000.00')->assertJsonPath('data.0.balance', '2000.00');
        $this->assertBooksAreConsistent();
        $this->assertProblem($this->req('POST', "/api/v1/staff-advances/{$a->json('data.id')}/reverse", ['reason' => 'x']), 409, 'advance_recovered');

        // Cancelling the sheet gives the advance back; then it can be reversed.
        $this->req('POST', "/api/v1/payroll-runs/{$s->json('data.id')}/cancel", ['reason' => 'Redo'])->assertOk();
        $this->assertSame('5000.00', $this->staffAdvancesBalance());
        $this->req('GET', '/api/v1/staff-advances')->assertJsonPath('data.0.recovered', '0.00')->assertJsonPath('data.0.status', 'open');
        $this->req('POST', "/api/v1/staff-advances/{$a->json('data.id')}/reverse", ['reason' => 'Given by mistake'])->assertOk()->assertJsonPath('data.status', 'reversed')
            ->assertJsonPath('data.balance', '0.00');
        $this->assertSame('0.00', $this->staffAdvancesBalance());
        $this->req('GET', "/api/v1/employees/{$this->rahim}")->assertJsonPath('data.advance_balance', '0.00');
        $this->assertBooksAreConsistent();
    }

    public function test_two_advances_are_taken_back_oldest_first_and_settle(): void
    {
        $first = $this->advance('1500', '1500', $this->month->toDateString())->assertCreated()->json('data.id');
        $second = $this->advance('3000', '1000', $this->month->addDays(3)->toDateString())->assertCreated()->json('data.id');
        $s = $this->req('POST', '/api/v1/payroll-runs', ['month' => $this->month->format('Y-m')])->assertCreated()->assertJsonPath('data.lines.0.advance_recovery', '2500.00');
        $this->req('POST', "/api/v1/payroll-runs/{$s->json('data.id')}/approve")->assertOk();
        $rows = collect($this->req('GET', '/api/v1/staff-advances')->json('data'))->keyBy('id');
        $this->assertSame(['settled', '0.00'], [$rows[$first]['status'], $rows[$first]['balance']]);
        $this->assertSame(['open', '2000.00'], [$rows[$second]['status'], $rows[$second]['balance']]);
        $this->req('GET', '/api/v1/staff-advances?status=open')->assertJsonCount(1, 'data');
        $this->assertBooksAreConsistent();
    }

    public function test_permissions_and_isolation(): void
    {
        $this->userWithRole('accountant');
        $acc = $this->login('accountant@meghna.example');
        $this->assertProblem($this->req('POST', '/api/v1/staff-advances', ['employee_id' => $this->rahim, 'money_account_id' => $this->cash->id, 'given_on' => $this->month->toDateString(),
            'amount' => '100', 'instalment' => '100'], $acc), 403, 'permission_denied');
        $this->req('GET', '/api/v1/staff-advances', token: $acc)->assertOk();
        $id = $this->advance('1000', '500')->assertCreated()->json('data.id');
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('POST', "/api/v1/staff-advances/{$id}/reverse", $ot, $other->id, ['reason' => 'x'], ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
        $this->assertSame([], $this->api('GET', '/api/v1/staff-advances', $ot, $other->id)->assertOk()->json('data'));
        $this->useBooks();
    }
}
