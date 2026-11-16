<?php

namespace App\Http\Requests\Payroll;

use App\Domain\Payroll\EmployeeService;
use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** EmployeeInput (PEP-520): POST needs name, joining date and basic salary; PATCH changes only what it sends. */
class EmployeeRequest extends StrictRequest
{
    private const MONEY = '/^\d{1,13}(\.\d{1,2})?$/';

    protected function allowedFields(): array
    {
        return EmployeeService::FIELDS;
    }

    public function rules(): array
    {
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$req, 'string', 'max:150'],
            'designation' => ['sometimes', 'nullable', 'string', 'max:80'],
            'department' => ['sometimes', 'nullable', 'string', 'max:80'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'national_id' => ['sometimes', 'nullable', 'string', 'max:30'],
            'joining_date' => [$req, 'date_format:Y-m-d'],
            'basic_salary' => [$req, 'string', 'regex:'.self::MONEY],
            'house_rent' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'medical' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'conveyance' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'pay_method' => ['sometimes', Rule::in(['cash', 'bank', 'mobile'])],
            'bank_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'account_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', Rule::in(['active', 'left'])],
            'left_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['basic_salary.regex' => 'Enter an amount of 0 or more with up to 2 decimals', 'house_rent.regex' => 'Enter an amount of 0 or more with up to 2 decimals',
            'medical.regex' => 'Enter an amount of 0 or more with up to 2 decimals', 'conveyance.regex' => 'Enter an amount of 0 or more with up to 2 decimals'];
    }
}
