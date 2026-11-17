<?php

namespace Tests\Feature\Payroll;

use App\Domain\Banking\MoneyAccountService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Festival bonus sheets (P3/P4; PEP-524): % of basic or fixed, minimum service, edits, posting to 6002, paying, cancelling, rules. */
class FestivalBonusTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private CarbonImmutable $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->month = CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->subMonthNoOverflow();
        $end = $this->month->endOfMonth();
        // Rahim and Karim have served over a year by the month end; Nila joined 3 months before it; Sohel has left.
        foreach ([['Rahim Uddin', '20000', $end->subYears(2)], ['Karim Hossain', '12345', $end->subMonths(13)], ['Nila Akter', '15000', $end->subMonths(3)],
            ['Sohel Rana', '18000', $end->subYears(3)]] as [$name, $basic, $joined]) {
            $id = $this->req('POST', '/api/v1/employees', ['name' => $name, 'joining_date' => $joined->toDateString(), 'basic_salary' => $basic, 'house_rent' => '5000'])
                ->assertCreated()->json('data.id');
            if ($name === 'Sohel Rana') {
                DB::table('employees')->where('id', $id)->update(['status' => 'left', 'left_on' => $this->month->subMonth()->toDateString()]);
            }
        }
        $this->useBooks();
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null, array $headers = [])
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, ($method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []) + $headers);
        $this->useBooks();

        return $r;
    }

    private function balance(string $code): string
    {
        $id = DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $code)->value('id');

        return number_format((float) DB::table('journal_lines')->where('ledger_id', $id)->where('is_posted', true)->sum(DB::raw('debit - credit')), 2, '.', '');
    }

    public function test_bonus_sheet_takes_a_share_of_basic_for_long_serving_staff(): void
    {
        $r = $this->req('POST', '/api/v1/payroll-runs', ['kind' => 'bonus', 'month' => $this->month->format('Y-m'), 'notes' => 'Eid-ul-Fitr'])->assertCreated();
        $r->assertJsonPath('data.kind', 'bonus')->assertJsonPath('data.status', 'draft')->assertJsonPath('data.employees_count', 2)
            ->assertJsonPath('data.label', 'Festival bonus, '.$this->month->format('F Y'))->assertJsonPath('data.notes', 'Eid-ul-Fitr')
            ->assertJsonPath('data.pay_date', $this->month->endOfMonth()->toDateString())
            ->assertJsonPath('data.lines.0.employee_name', 'Rahim Uddin')->assertJsonPath('data.lines.0.basic_salary', '20000.00')
            ->assertJsonPath('data.lines.0.house_rent', '0.00')->assertJsonPath('data.lines.0.other_earnings', '20000.00')
            ->assertJsonPath('data.lines.0.gross', '20000.00')->assertJsonPath('data.lines.1.net', '12345.00')->assertJsonPath('data.total_net', '32345.00');

        // One bonus sheet a month; a salary sheet for the same month is separate.
        $this->assertProblem($this->req('POST', '/api/v1/payroll-runs', ['kind' => 'bonus', 'month' => $this->month->format('Y-m')]), 409, 'payroll_run_exists');
        $this->req('POST', '/api/v1/payroll-runs', ['month' => $this->month->format('Y-m')])->assertCreated()->assertJsonPath('data.employees_count', 3);
        $this->req('GET', '/api/v1/payroll-runs?kind=bonus')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_percent_fixed_and_minimum_service(): void
    {
        $m = $this->month->format('Y-m');
        $r = $this->req('POST', '/api/v1/payroll-runs', ['kind' => 'bonus', 'month' => $m, 'bonus_percent' => '50', 'min_service_months' => 3])->assertCreated();
        $r->assertJsonPath('data.employees_count', 3)->assertJsonPath('data.lines.1.other_earnings', '6172.50')->assertJsonPath('data.lines.2.other_earnings', '7500.00');
        $this->req('DELETE', "/api/v1/payroll-runs/{$r->json('data.id')}")->assertNoContent();

        $this->req('POST', '/api/v1/payroll-runs', ['kind' => 'bonus', 'month' => $m, 'bonus_amount' => '5000'])->assertCreated()
            ->assertJsonPath('data.employees_count', 2)->assertJsonPath('data.total_net', '10000.00');

        $this->assertProblem($this->req('POST', '/api/v1/payroll-runs', ['kind' => 'bonus', 'month' => $this->month->subMonth()->format('Y-m'), 'min_service_months' => 60]), 422, 'no_employees');
        foreach ([['bonus_percent' => '0'], ['bonus_amount' => '-1'], ['min_service_months' => 200], ['kind' => 'gift']] as $bad) {
            $this->req('POST', '/api/v1/payroll-runs', $bad + ['kind' => 'bonus', 'month' => $m])->assertStatus(422);
        }
        $this->req('POST', '/api/v1/payroll-runs', ['month' => $m, 'bonus_percent' => '50'])->assertStatus(422)->assertJsonPath('errors.bonus_percent.0', 'Only for a bonus sheet');
    }

    public function test_edit_approve_pay_and_cancel(): void
    {
        $r = $this->req('POST', '/api/v1/payroll-runs', ['kind' => 'bonus', 'month' => $this->month->format('Y-m')])->assertCreated();
        $id = $r->json('data.id');
        $lines = $r->json('data.lines');
        $put = fn (array $body) => $this->req('PUT', "/api/v1/payroll-runs/{$id}", $body, headers: ['If-Match' => 'W/"'.$this->req('GET', "/api/v1/payroll-runs/{$id}")->json('data.version').'"']);

        $put(['lines' => [['id' => $lines[0]['id'], 'other_earnings' => '18000', 'deductions' => '500']]])->assertOk()
            ->assertJsonPath('data.lines.0.gross', '18000.00')->assertJsonPath('data.lines.0.net', '17500.00')->assertJsonPath('data.total_net', '29845.00');
        $put(['lines' => [['id' => $lines[0]['id'], 'overtime' => '100']]])->assertStatus(422)->assertSee('Not on a festival bonus sheet');
        $put(['lines' => [['id' => $lines[1]['id'], 'advance_recovery' => '100']]])->assertStatus(422);
        $put(['lines' => [['id' => $lines[1]['id'], 'deductions' => '20000']]])->assertStatus(422);

        $this->req('POST', "/api/v1/payroll-runs/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(['29845.00', '0.00', '-29845.00'], [$this->balance('6002'), $this->balance('6001'), $this->balance('2201')]);
        $this->assertBooksAreConsistent();

        $cash = app(MoneyAccountService::class)->create(['account_type' => 'cash', 'opening_balance' => '50000.00', 'opening_date' => $this->month->toDateString()]);
        $p = $this->req('POST', "/api/v1/payroll-runs/{$id}/payments", ['money_account_id' => $cash->id, 'paid_on' => CarbonImmutable::now('Asia/Dhaka')->toDateString()])
            ->assertCreated()->assertJsonPath('data.amount', '29845.00');
        $this->req('GET', "/api/v1/payroll-runs/{$id}")->assertJsonPath('data.payment_status', 'paid');
        $this->assertSame('0.00', $this->balance('2201'));

        $this->req('POST', "/api/v1/payroll-payments/{$p->json('data.id')}/reverse", ['reason' => 'Wrong account'])->assertOk();
        $this->req('POST', "/api/v1/payroll-runs/{$id}/cancel", ['reason' => 'Eid moved'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(['0.00', '0.00'], [$this->balance('6002'), $this->balance('2201')]);
        $this->assertBooksAreConsistent();

        // Cancelled: a new bonus sheet for the month is allowed again.
        $this->req('POST', '/api/v1/payroll-runs', ['kind' => 'bonus', 'month' => $this->month->format('Y-m')])->assertCreated();
    }

    public function test_permissions(): void
    {
        $this->userWithRole('sales');
        $this->assertProblem($this->req('POST', '/api/v1/payroll-runs', ['kind' => 'bonus', 'month' => $this->month->format('Y-m')], $this->login('sales@meghna.example')), 403, 'permission_denied');
    }
}
