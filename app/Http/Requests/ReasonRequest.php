<?php

namespace App\Http\Requests;

/** ReasonRequest (05): a required reason, audited with the action (period unlock, …). */
class ReasonRequest extends StrictRequest
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
        return ['reason.required' => 'Give the reason; it is kept in the audit log'];
    }
}
