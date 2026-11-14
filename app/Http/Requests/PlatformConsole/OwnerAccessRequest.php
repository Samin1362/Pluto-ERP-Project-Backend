<?php

namespace App\Http\Requests\PlatformConsole;

use App\Http\Requests\StrictRequest;

/** POST /platform/companies/{id}/owner-access (OwnerAccessInput; PEP-496). */
class OwnerAccessRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['reason'];
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Say why you are joining this company; its owners see it'];
    }
}
