<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /account-groups query (05 getAccountGroups): `as_of` (default today), `with_ledgers` and `zero` (default true). */
class AccountTreeRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['as_of', 'with_ledgers', 'zero'];
    }

    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'with_ledgers' => ['sometimes', Rule::in(['true', 'false'])],
            'zero' => ['sometimes', Rule::in(['true', 'false'])],
        ];
    }
}
