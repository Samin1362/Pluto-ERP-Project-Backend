<?php

namespace App\Http\Requests\PlatformConsole;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /platform/companies query (PEP-483). */
class PlatformCompanyListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'q', 'status'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:190'],
            'status' => ['sometimes', 'nullable', Rule::in(['active', 'suspended', 'archived'])],
        ];
    }
}
