<?php

namespace App\Http\Requests\PlatformConsole;

use App\Http\Requests\StrictRequest;

/** POST /platform/companies/{id}/billing (CompanyBillingInput; PEP-503). */
class CompanyBillingRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['price', 'paid_until', 'grace_days'];
    }

    public function rules(): array
    {
        return [
            'price' => ['sometimes', 'nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'paid_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'grace_days' => ['sometimes', 'integer', 'between:0,60'],
        ];
    }

    public function messages(): array
    {
        return ['price.regex' => 'Enter the monthly price like 3000 or 2500.50', 'paid_until.date_format' => 'Enter a date like 2026-12-31'];
    }
}
