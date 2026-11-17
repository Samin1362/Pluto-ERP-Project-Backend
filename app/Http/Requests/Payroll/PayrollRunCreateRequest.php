<?php

namespace App\Http\Requests\Payroll;

use App\Http\Requests\StrictRequest;

/** PayrollRunCreate (PEP-521). */
class PayrollRunCreateRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['month', 'pay_date', 'kind', 'bonus_percent', 'bonus_amount', 'min_service_months', 'notes'];
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'pay_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            // PEP-524: festival bonus sheets
            'kind' => ['sometimes', 'nullable', 'in:salary,bonus'],
            'bonus_percent' => ['sometimes', 'nullable', 'prohibited_unless:kind,bonus', 'numeric', 'gt:0', 'max:500', 'decimal:0,2'],
            'bonus_amount' => ['sometimes', 'nullable', 'prohibited_unless:kind,bonus', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'min_service_months' => ['sometimes', 'nullable', 'prohibited_unless:kind,bonus', 'integer', 'between:0,120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['month.regex' => 'Choose a month like 2026-11', '*.prohibited_unless' => 'Only for a bonus sheet'];
    }
}
