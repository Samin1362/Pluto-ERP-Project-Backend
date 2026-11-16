<?php

namespace App\Http\Requests\Payroll;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** PayrollPaymentInput (PEP-522). */
class PayrollPaymentRequest extends StrictRequest
{
    private const MONEY = '/^\d{1,13}(\.\d{1,2})?$/';

    protected function allowedFields(): array
    {
        return ['money_account_id', 'paid_on', 'reference', 'lines'];
    }

    public function rules(): array
    {
        return [
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'lines' => ['sometimes', 'array', 'max:1000'],
            'lines.*' => ['array:payroll_line_id,amount'],
            'lines.*.payroll_line_id' => ['required', 'integer'],
            'lines.*.amount' => ['required', 'string', 'regex:'.self::MONEY],
        ];
    }

    public function messages(): array
    {
        return ['lines.*.amount.regex' => 'Enter an amount with up to 2 decimals'];
    }
}
