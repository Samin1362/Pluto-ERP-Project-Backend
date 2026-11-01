<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** POST /letters-of-credit/{id}/transition (LcTransition). */
class LcTransitionRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['action', 'bank_reference', 'date', 'reason'];
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['submit', 'mark_opened', 'mark_refused', 'documents_presented', 'payment_due', 'cancel', 'expire', 'reopen'])],
            'bank_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
