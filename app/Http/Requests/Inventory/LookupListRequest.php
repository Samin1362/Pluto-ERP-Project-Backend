<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /units, /product-categories, /stock-groups query (05 LISTQ): paging, sort by name and free-text search. */
class LookupListRequest extends StrictRequest
{
    public const SORTS = ['name', '-name'];

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
