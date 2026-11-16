<?php

namespace App\Http\Requests\Payroll;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /staff-advances query (PEP-523). */
class StaffAdvanceListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'employee_id', 'status'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'employee_id' => ['sometimes', 'integer', Rule::exists('employees', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'status' => ['sometimes', Rule::in(['open', 'settled', 'reversed'])],
        ];
    }
}
