<?php

namespace Tests\Feature\Payroll;

use App\Domain\Payroll\PayrollRunService;
use App\Domain\Shared\Money;
use App\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Salary sheets (P3, P4; PEP-521): a month's sheet, part months, draft changes, approve posts, cancel reverses, permissions. */
class PayrollRunTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    private CarbonImmutable $month; // last month: its period is open and its end has passed

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->month = CarbonImmutable::now('Asia/Dhaka')->startOfMonth()->subMonthNoOverflow();
        $this->employee('Rahim Uddin', '2025-01-01', '15000', ['house_rent' => '7500', 'medical' => '1500', 'conveyance' => '1000']); // 25,000 gross
        $this->employee('Karim Hossain', $this->month->addDays(15)->toDateString(), '12000'); // joined on the 16th
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null, array $headers = [])
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, ($method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []) + $headers);
        $this->useBooks();

        return $r;
    }

    private function employee(string $name, string $joined, string $basic, array $extra = [])
    {
        return $this->req('POST', '/api/v1/employees', $extra + ['name' => $name, 'joining_date' => $joined, 'basic_salary' => $basic])->assertCreated()->json('data');
    }

    private function balance(string $code): string
    {
        $id = DB::table('ledgers')->where('company_id', $this->books->id)->where('code', $code)->value('id');

        return number_format((float) DB::table('journal_lines')->where('ledger_id', $id)->where('is_posted', true)->sum(DB::raw('debit - credit')), 2, '.', '');
    }

    public function test_a_month_is_prepared_approved_and_cancelled(): void
    {
        $m = $this->month->format('Y-m');
        $r = $this->req('POST', '/api/v1/payroll-runs', ['month' => $m])->assertCreated();
        $dim = $this->month->daysInMonth;
        $karimDays = $dim - 15;
        $karimPay = (string) PayrollRunService::prorate('12000', $karimDays, $dim);
        $r->assertJsonPath('data.status', 'draft')->assertJsonPath('data.employees_count', 2)->assertJsonPath('data.label', 'Salaries, '.$this->month->format('F Y'))
            ->assertJsonPath('data.pay_date', $this->month->endOfMonth()->toDateString())
            ->assertJsonPath('data.lines.0.employee_name', 'Rahim Uddin')->assertJsonPath('data.lines.0.gross', '25000.00')->assertJsonPath('data.lines.0.days_paid', $dim)
            ->assertJsonPath('data.lines.1.days_paid', $karimDays)->assertJsonPath('data.lines.1.basic_salary', $karimPay);
        $id = $r->json('data.id');
        $rahim = $r->json('data.lines.0.id');

        $this->assertProblem($this->req('POST', '/api/v1/payroll-runs', ['month' => $m]), 409, 'payroll_run_exists');
        $u = $this->req('PUT', "/api/v1/payroll-runs/{$id}", ['notes' => 'With overtime', 'lines' => [['id' => $rahim, 'overtime' => '2000', 'deductions' => '500', 'note' => '2 days late']]],
            headers: ['If-Match' => 'W/"'.$r->json('data.version').'"'])->assertOk();
        $u->assertJsonPath('data.lines.0.gross', '27000.00')->assertJsonPath('data.lines.0.net', '26500.00')->assertJsonPath('data.lines.0.note', '2 days late')
            ->assertJsonPath('data.total_deductions', '500.00')->assertJsonPath('data.total_net', (string) Money::of('26500')->plus(Money::of($karimPay)));
        $this->assertProblem($this->req('PUT', "/api/v1/payroll-runs/{$id}", ['notes' => 'x'], headers: ['If-Match' => 'W/"'.$r->json('data.version').'"']), 409, 'version_conflict');

        $a = $this->req('POST', "/api/v1/payroll-runs/{$id}/approve")->assertOk();
        $net = $u->json('data.total_net');
        $a->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approved_by.name', 'Owner');
        $this->assertNotNull($a->json('data.voucher_number'));
        $this->assertSame([$net, '-'.$net], [$this->balance('6001'), $this->balance('2201')], 'Dr salaries (gross − deductions) / Cr payable (net)');
        $this->assertBooksAreConsistent();
        $this->assertProblem($this->req('PUT', "/api/v1/payroll-runs/{$id}", ['notes' => 'late'], headers: ['If-Match' => 'W/"'.$a->json('data.version').'"']), 409, 'payroll_run_not_draft');
        $this->assertProblem($this->req('DELETE', "/api/v1/payroll-runs/{$id}"), 409, 'payroll_run_not_draft');

        $c = $this->req('POST', "/api/v1/payroll-runs/{$id}/cancel", ['reason' => 'Wrong month'])->assertOk();
        $c->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Wrong month');
        $this->assertSame(['0.00', '0.00'], [$this->balance('6001'), $this->balance('2201')]);
        $this->assertBooksAreConsistent();
        $this->assertProblem($this->req('POST', "/api/v1/payroll-runs/{$id}/cancel", ['reason' => 'again']), 409, 'payroll_run_not_approved');
        // The month can be done again.
        $this->req('POST', '/api/v1/payroll-runs', ['month' => $m])->assertCreated();
        $this->req('GET', '/api/v1/payroll-runs')->assertOk()->assertJsonCount(2, 'data')->assertJsonMissingPath('data.0.lines');
        $this->req('GET', '/api/v1/payroll-runs?status=cancelled')->assertJsonCount(1, 'data');
    }

    public function test_validation_drafts_and_who_is_on_a_sheet(): void
    {
        $this->assertProblem($this->req('POST', '/api/v1/payroll-runs', ['month' => '2026-13']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/payroll-runs', ['month' => '2020-01']), 422, 'no_employees');
        $r = $this->req('POST', '/api/v1/payroll-runs', ['month' => $this->month->format('Y-m')])->assertCreated();
        $id = $r->json('data.id');
        $this->assertProblem($this->req('PUT', "/api/v1/payroll-runs/{$id}", ['lines' => [['id' => $r->json('data.lines.0.id'), 'deductions' => '99999']]],
            headers: ['If-Match' => 'W/"'.$r->json('data.version').'"']), 422, 'validation_failed');
        $this->assertProblem($this->req('PUT', "/api/v1/payroll-runs/{$id}", ['lines' => [['id' => 999999, 'overtime' => '1']]],
            headers: ['If-Match' => 'W/"'.$r->json('data.version').'"']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', "/api/v1/payroll-runs/{$id}/cancel", ['reason' => 'x']), 409, 'payroll_run_not_approved');
        $this->req('DELETE', "/api/v1/payroll-runs/{$id}")->assertNoContent();
        $this->assertSame(0, DB::table('payroll_lines')->count());

        // Someone who left on the 10th is paid for 10 days of that month.
        $e = collect($this->req('GET', '/api/v1/employees')->json('data'))->firstWhere('name', 'Rahim Uddin');
        $this->req('PATCH', "/api/v1/employees/{$e['id']}", ['status' => 'left', 'left_on' => $this->month->addDays(9)->toDateString()], headers: ['If-Match' => "W/\"{$e['version']}\""])->assertOk();
        $left = $this->req('POST', '/api/v1/payroll-runs', ['month' => $this->month->format('Y-m')])->assertCreated();
        $left->assertJsonPath('data.lines.0.employee_name', 'Rahim Uddin')->assertJsonPath('data.lines.0.days_paid', 10);
        $this->assertSame('6193.55', (string) PayrollRunService::prorate('12000', 16, 31), 'HALF_UP to the paisa');
    }

    public function test_permissions_plan_and_isolation(): void
    {
        $this->userWithRole('accountant');
        $this->userWithRole('sales');
        $acc = $this->login('accountant@meghna.example');
        $id = $this->req('POST', '/api/v1/payroll-runs', ['month' => $this->month->format('Y-m')], $acc)->assertCreated()->json('data.id');
        $this->assertProblem($this->req('POST', "/api/v1/payroll-runs/{$id}/approve", token: $acc), 403, 'permission_denied');
        $this->assertProblem($this->req('GET', '/api/v1/payroll-runs', token: $this->login('sales@meghna.example')), 403, 'permission_denied');

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/payroll-runs/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertProblem($this->api('POST', "/api/v1/payroll-runs/{$id}/approve", $ot, $other->id, [], ['Idempotency-Key' => (string) Str::uuid()]), 404, 'not_found');
        $this->useBooks();

        $this->books->forceFill(['plan_id' => Plan::where('slug', 'basic')->value('id')])->save();
        $this->assertProblem($this->req('GET', '/api/v1/payroll-runs'), 403, 'module_not_in_plan');
    }
}
