<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\StrictRequest;

/** AccountMappings body (05 putAccountMappings): rows as GET returns them; required_nature and description are read-only. */
class AccountMappingsRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['mappings'];
    }

    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array', 'min:1', 'max:60'],
            'mappings.*.mapping_key' => ['required', 'string', 'max:60', 'distinct'],
            'mappings.*.ledger_id' => ['sometimes', 'nullable', 'integer'],
            'mappings.*.account_group_id' => ['sometimes', 'nullable', 'integer'],
            'mappings.*.required_nature' => ['sometimes', 'nullable', 'string', 'max:60'],
            'mappings.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
