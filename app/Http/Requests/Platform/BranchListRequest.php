<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /branches query (05 getBranches): paging, sort and free-text search; unknown parameters are a 422. */
class BranchListRequest extends StrictRequest
{
    public const SORTS = ['name', '-name', 'code', '-code'];

    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q'];
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
