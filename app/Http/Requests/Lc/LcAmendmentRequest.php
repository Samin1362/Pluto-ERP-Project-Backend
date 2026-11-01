<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** POST /letters-of-credit/{id}/amendments (LcAmendmentInput; BR-041). */
class LcAmendmentRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['requested_on', 'status', 'changes', 'other_change', 'reason', 'bank_fee'];
    }

    public function rules(): array
    {
        return [
            'requested_on' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['requested', 'issued', 'accepted'])],
            'changes' => ['required', 'array', 'min:1'],
            'changes.*.field_key' => ['required', 'distinct', Rule::in(['expiry_date', 'latest_shipment_date', 'amount', 'tolerance_pct', 'other'])],
            'changes.*.new_value' => ['required', 'string', 'max:120'],
            'other_change' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'bank_fee' => ['sometimes', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'changes.required' => 'Say what changes',
        ];
    }
}
