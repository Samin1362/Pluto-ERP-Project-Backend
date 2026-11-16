<?php

namespace App\Domain\Payroll;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DocumentNumberService;
use App\Domain\Shared\Money;
use App\Models\Employee;
use App\Models\NumberSequence;
use App\Support\Problem\ApiProblem;
use Illuminate\Support\Facades\DB;

/**
 * Employees (PEP-520): the only place that changes them. Codes come from the company's EMP- series (made on first use
 * for a company provisioned before payroll). Leaving needs the date; a person who left can come back (status active).
 */
class EmployeeService
{
    public const FIELDS = ['name', 'designation', 'department', 'mobile', 'email', 'national_id', 'joining_date', 'basic_salary', 'house_rent',
        'medical', 'conveyance', 'pay_method', 'bank_name', 'account_no', 'status', 'left_on', 'notes'];

    private const AUDITED = ['name', 'designation', 'department', 'basic_salary', 'house_rent', 'medical', 'conveyance', 'pay_method', 'status', 'left_on'];

    public function __construct(private readonly DocumentNumberService $numbers, private readonly AuditLogger $audit) {}

    public function create(array $in): Employee
    {
        return DB::transaction(function () use ($in) {
            if (! NumberSequence::where('document_type', 'employee')->whereNull('fiscal_year_id')->exists()) {
                NumberSequence::create(['fiscal_year_id' => null, 'document_type' => 'employee', 'prefix' => 'EMP-', 'next_number' => 1, 'padding' => 4, 'reset_policy' => 'never']);
            }
            $e = new Employee($this->clean($in) + ['house_rent' => '0', 'medical' => '0', 'conveyance' => '0', 'pay_method' => 'cash', 'status' => 'active']);
            $e->code = $this->numbers->next('employee')['number'];
            $this->assertLeaving($e);
            $e->save();
            $this->audit->record('created', $e, null, $e->only(self::AUDITED) + ['code' => $e->code], type: 'employees');

            return $e->refresh();
        });
    }

    public function update(Employee $e, array $in, int $expectedVersion): Employee
    {
        return DB::transaction(function () use ($e, $in, $expectedVersion) {
            $e = Employee::whereKey($e->id)->lockForUpdate()->firstOrFail();
            if ($e->lock_version !== $expectedVersion) {
                throw new ApiProblem('version_conflict', extra: ['current_version' => $e->lock_version]);
            }
            $old = $e->only(self::AUDITED);
            $e->fill($this->clean($in));
            if ($e->status === 'active') {
                $e->left_on = null;
            }
            $this->assertLeaving($e);
            $e->save();
            $this->audit->record('updated', $e, $old, $e->only(self::AUDITED), type: 'employees');

            return $e->refresh();
        });
    }

    /** Employee (05 schema). */
    public static function resource(Employee $e): array
    {
        $gross = Money::of((string) $e->basic_salary)->plus(Money::of((string) $e->house_rent))->plus(Money::of((string) $e->medical))->plus(Money::of((string) $e->conveyance));

        return ['id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'designation' => $e->designation, 'department' => $e->department, 'mobile' => $e->mobile,
            'email' => $e->email, 'national_id' => $e->national_id, 'joining_date' => $e->joining_date->toDateString(),
            'basic_salary' => (string) Money::of((string) $e->basic_salary), 'house_rent' => (string) Money::of((string) $e->house_rent),
            'medical' => (string) Money::of((string) $e->medical), 'conveyance' => (string) Money::of((string) $e->conveyance), 'gross_salary' => (string) $gross,
            'pay_method' => $e->pay_method, 'bank_name' => $e->bank_name, 'account_no' => $e->account_no, 'status' => $e->status,
            'left_on' => $e->left_on?->toDateString(), 'notes' => $e->notes, 'version' => $e->lock_version];
    }

    private function clean(array $in): array
    {
        $row = array_intersect_key($in, array_flip(self::FIELDS));
        foreach (['house_rent', 'medical', 'conveyance'] as $k) {
            if (array_key_exists($k, $row) && $row[$k] === null) {
                $row[$k] = '0';
            }
        }

        return $row;
    }

    private function assertLeaving(Employee $e): void
    {
        if ($e->status === 'left' && ! $e->left_on) {
            throw new ApiProblem('validation_failed', errors: ['left_on' => ['Enter the date they left']]);
        }
        if ($e->left_on && $e->joining_date && $e->left_on->lt($e->joining_date)) {
            throw new ApiProblem('validation_failed', errors: ['left_on' => ['They cannot leave before they joined']]);
        }
    }
}
