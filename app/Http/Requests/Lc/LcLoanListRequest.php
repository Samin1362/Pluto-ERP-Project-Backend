<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /lc-loans (M4, M7). */
class LcLoanListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'status', 'bank_account_id', 'letter_of_credit_id'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in(['due_date', '-due_date', 'start_date', '-start_date'])],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['open', 'repaid', 'reversed'])],
            'bank_account_id' => ['sometimes', 'integer'],
            'letter_of_credit_id' => ['sometimes', 'integer'],
        ];
    }
}
