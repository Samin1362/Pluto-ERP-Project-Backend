<?php

namespace App\Http\Requests\Payroll;

use App\Http\Requests\StrictRequest;

/** PayrollRunCreate (PEP-521). */
class PayrollRunCreateRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['month', 'pay_date'];
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'pay_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return ['month.regex' => 'Choose a month like 2026-11'];
    }
}
