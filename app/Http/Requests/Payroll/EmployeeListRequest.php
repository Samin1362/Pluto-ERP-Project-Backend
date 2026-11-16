<?php

namespace App\Http\Requests\Payroll;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /employees query (PEP-520). */
class EmployeeListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'status', 'department'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'sort' => ['sometimes', Rule::in(['code', '-code', 'name', '-name', 'joining_date', '-joining_date'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', Rule::in(['active', 'left', 'all'])],
            'department' => ['sometimes', 'nullable', 'string', 'max:80'],
        ];
    }
}
