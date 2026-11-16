<?php

namespace App\Http\Requests\Payroll;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** StaffAdvanceInput (PEP-523). */
class StaffAdvanceRequest extends StrictRequest
{
    private const MONEY = '/^\d{1,13}(\.\d{1,2})?$/';

    protected function allowedFields(): array
    {
        return ['employee_id', 'money_account_id', 'given_on', 'amount', 'instalment', 'reference', 'notes'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $c)],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', $c)],
            'given_on' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'regex:'.self::MONEY],
            'instalment' => ['required', 'string', 'regex:'.self::MONEY],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['amount.regex' => 'Enter an amount with up to 2 decimals', 'instalment.regex' => 'Enter an amount with up to 2 decimals'];
    }
}
