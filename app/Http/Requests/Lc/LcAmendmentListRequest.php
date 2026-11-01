<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /lc-amendments (M5). */
class LcAmendmentListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'status'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['date', '-date'])],
            'status' => ['sometimes', Rule::in(['requested', 'issued', 'accepted', 'refused', 'pending'])],
        ];
    }
}
