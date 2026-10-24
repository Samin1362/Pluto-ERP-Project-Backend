<?php

namespace App\Http\Requests\Parties;

use App\Http\Requests\StrictRequest;

/** BulkIds (05 §10): up to 100 distinct ids for a bulk action (backlog P2.1-BE-03). */
class BulkIdsRequest extends StrictRequest
{
    public const MAX = 100;

    protected function allowedFields(): array
    {
        return ['ids'];
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer', 'min:1', 'distinct'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Choose at least one record',
            'ids.min' => 'Choose at least one record',
            'ids.max' => 'Choose at most '.self::MAX.' records at a time',
        ];
    }
}
