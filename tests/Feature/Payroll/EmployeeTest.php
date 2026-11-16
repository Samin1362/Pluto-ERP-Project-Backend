<?php

namespace Tests\Feature\Payroll;

use App\Models\Plan;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Employees (P1, P2; PEP-520): EMP codes, gross from the server, leaving, ETag, private permissions, plan module, isolation. */
class EmployeeTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null, array $headers = [])
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, ($method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []) + $headers);
        $this->useBooks();

        return $r;
    }

    private function rahim(array $extra = [], ?string $token = null)
    {
        return $this->req('POST', '/api/v1/employees', $extra + ['name' => 'Rahim Uddin', 'designation' => 'Salesman', 'department' => 'Sales', 'joining_date' => '2025-01-01',
            'basic_salary' => '15000', 'house_rent' => '7500', 'medical' => '1500', 'conveyance' => '1000', 'pay_method' => 'mobile', 'account_no' => '01711000111'], $token);
    }

    public function test_add_list_change_and_mark_as_left(): void
    {
        $r = $this->rahim()->assertCreated();
        $r->assertJsonPath('data.code', 'EMP-0001')->assertJsonPath('data.gross_salary', '25000.00')->assertJsonPath('data.basic_salary', '15000.00')
            ->assertJsonPath('data.status', 'active')->assertJsonPath('data.pay_method', 'mobile');
        $id = $r->json('data.id');
        $this->req('POST', '/api/v1/employees', ['name' => 'Karim', 'joining_date' => '2026-07-01', 'basic_salary' => '12000'])->assertCreated()
            ->assertJsonPath('data.code', 'EMP-0002')->assertJsonPath('data.gross_salary', '12000.00')->assertJsonPath('data.pay_method', 'cash');

        $this->req('GET', '/api/v1/employees?q=rahim')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'EMP-0001');
        $this->req('GET', '/api/v1/employees?department=Sales')->assertJsonCount(1, 'data');
        $this->req('GET', '/api/v1/employees?sort=-code')->assertJsonPath('data.0.code', 'EMP-0002');

        $v = $this->req('GET', "/api/v1/employees/{$id}")->assertOk()->assertHeader('ETag', 'W/"1"')->json('data.version');
        $this->assertProblem($this->req('PATCH', "/api/v1/employees/{$id}", ['status' => 'left'], headers: ['If-Match' => "W/\"{$v}\""]), 422, 'validation_failed');
        $this->assertProblem($this->req('PATCH', "/api/v1/employees/{$id}", ['status' => 'left', 'left_on' => '2024-12-31'], headers: ['If-Match' => "W/\"{$v}\""]), 422, 'validation_failed');
        $u = $this->req('PATCH', "/api/v1/employees/{$id}", ['basic_salary' => '16000', 'status' => 'left', 'left_on' => '2026-10-31'], headers: ['If-Match' => "W/\"{$v}\""])->assertOk();
        $u->assertJsonPath('data.gross_salary', '26000.00')->assertJsonPath('data.status', 'left')->assertJsonPath('data.left_on', '2026-10-31');
        $this->assertProblem($this->req('PATCH', "/api/v1/employees/{$id}", ['name' => 'X'], headers: ['If-Match' => "W/\"{$v}\""]), 409, 'version_conflict');
        $this->req('GET', '/api/v1/employees')->assertJsonCount(1, 'data'); // active only by default
        $this->req('GET', '/api/v1/employees?status=left')->assertJsonPath('data.0.code', 'EMP-0001');
        // Coming back clears the leaving date.
        $this->req('PATCH', "/api/v1/employees/{$id}", ['status' => 'active'], headers: ['If-Match' => 'W/"'.$u->json('data.version').'"'])->assertOk()->assertJsonPath('data.left_on', null);

        $this->assertProblem($this->req('POST', '/api/v1/employees', ['name' => 'No pay', 'joining_date' => '2026-01-01', 'basic_salary' => '-5']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/employees', ['name' => 'Extra', 'joining_date' => '2026-01-01', 'basic_salary' => '1', 'salary' => '1']), 422, 'validation_failed');
    }

    public function test_salaries_are_private_by_role(): void
    {
        $this->rahim()->assertCreated();
        $this->userWithRole('finance');
        $this->userWithRole('accountant');
        $this->userWithRole('sales');
        $this->userWithRole('viewer');
        $finance = $this->login('finance@meghna.example');
        $accountant = $this->login('accountant@meghna.example');
        $id = $this->rahim(['name' => 'By finance'], $finance)->assertCreated()->json('data.id');
        $this->req('GET', '/api/v1/employees', token: $accountant)->assertOk()->assertJsonCount(2, 'data');
        $this->rahim(['name' => 'By accountant'], $accountant)->assertCreated();
        $this->assertProblem($this->req('PATCH', "/api/v1/employees/{$id}", ['name' => 'Y'], $accountant, ['If-Match' => 'W/"1"']), 403, 'permission_denied');
        foreach (['sales', 'viewer'] as $role) {
            $this->assertProblem($this->req('GET', '/api/v1/employees', token: $this->login("{$role}@meghna.example")), 403, 'permission_denied');
        }
    }

    public function test_payroll_is_a_plan_module_and_companies_are_isolated(): void
    {
        $id = $this->rahim()->assertCreated()->json('data.id');
        $this->books->forceFill(['plan_id' => Plan::where('slug', 'basic')->value('id')])->save();
        $this->assertProblem($this->req('GET', '/api/v1/employees'), 403, 'module_not_in_plan');
        $this->books->forceFill(['plan_id' => Plan::where('slug', 'business')->value('id')])->save();
        $this->req('GET', '/api/v1/employees')->assertOk();

        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('padma@agro.example'));
        $ot = $this->login('padma@agro.example');
        $this->assertProblem($this->api('GET', "/api/v1/employees/{$id}", $ot, $other->id), 404, 'not_found');
        $this->assertSame([], $this->api('GET', '/api/v1/employees', $ot, $other->id)->assertOk()->json('data'));
        // A company made before payroll gets its EMP series on first use.
        $this->api('POST', '/api/v1/employees', $ot, $other->id, ['name' => 'Padma one', 'joining_date' => '2026-01-01', 'basic_salary' => '10000'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.code', 'EMP-0001');
        $this->useBooks();
    }
}
