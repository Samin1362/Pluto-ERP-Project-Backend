<?php

namespace App\Http\Requests\Parties;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /parties query: type is required; unknown parameters are a 422 (05 §1 filtering). */
class PartyListRequest extends StrictRequest
{
    public const SORTS = ['name', '-name', 'code', '-code', 'balance', '-balance', 'created_at', '-created_at'];

    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'type', 'status', 'balance', 'area', 'group_id'];
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(['customer', 'supplier'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'prospect'])],
            'balance' => ['sometimes', Rule::in(['outstanding', 'zero'])],
            'q' => ['sometimes', 'string', 'max:100'],
            'area' => ['sometimes', 'string', 'max:80'],
            'group_id' => ['sometimes', 'integer'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
