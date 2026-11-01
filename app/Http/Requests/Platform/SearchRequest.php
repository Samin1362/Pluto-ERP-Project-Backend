<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\StrictRequest;
use App\Queries\SearchQuery;
use Illuminate\Validation\Rule;

/** GET /search (05; FR-012; backlog P14.1-BE-03): q of 2+ characters, an optional type, up to 20 hits per type. */
class SearchRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['q', 'type', 'limit'];
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'type' => ['sometimes', Rule::in([...array_keys(SearchQuery::TYPES), 'letter_of_credit'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return ['q.required' => 'Type at least 2 characters', 'q.min' => 'Type at least 2 characters'];
    }
}
