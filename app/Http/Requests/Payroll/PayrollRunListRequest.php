<?php

namespace App\Http\Requests\Payroll;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /payroll-runs query (PEP-521). */
class PayrollRunListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'status', 'kind'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'status' => ['sometimes', Rule::in(['draft', 'approved', 'cancelled'])],
            'kind' => ['sometimes', Rule::in(['salary', 'bonus'])],
        ];
    }
}
