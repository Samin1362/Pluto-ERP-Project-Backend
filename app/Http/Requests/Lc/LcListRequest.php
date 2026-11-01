<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /letters-of-credit (M2). */
class LcListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'group', 'bank_account_id', 'party_id', 'attention'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(['created', '-created', 'number', '-number', 'expiry', '-expiry', 'amount', '-amount'])],
            'group' => ['sometimes', Rule::in(['pipeline', 'active', 'closed', 'all'])],
            'bank_account_id' => ['sometimes', 'integer'],
            'party_id' => ['sometimes', 'integer'],
            'attention' => ['sometimes', Rule::in(['true', 'false'])],
        ];
    }
}
