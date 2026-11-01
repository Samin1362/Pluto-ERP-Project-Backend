<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /lc-charges (M7). */
class LcChargeListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'letter_of_credit_id', 'status'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['date', '-date'])],
            'letter_of_credit_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::in(['unpaid', 'paid'])],
        ];
    }
}
