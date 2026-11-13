<?php

namespace App\Http\Requests\PlatformConsole;

use App\Domain\Platform\SupportAccessService;
use App\Http\Requests\StrictRequest;

/** POST /platform/companies/{id}/support-access (SupportAccessRequest; PEP-485). */
class SupportAccessRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['reason', 'minutes'];
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'minutes' => ['sometimes', 'integer', 'between:'.SupportAccessService::MIN_MINUTES.','.SupportAccessService::MAX_MINUTES],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Say why you are opening this company; the owner sees it'];
    }
}
