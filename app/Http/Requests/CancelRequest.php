<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** CancelRequest (05): a reason is required (BR-024); an optional note and cancellation date (default today). */
class CancelRequest extends StrictRequest
{
    public const REASONS = ['customer_cancelled', 'entered_by_mistake', 'wrong_party', 'duplicate', 'other'];

    protected function allowedFields(): array
    {
        return ['reason', 'note', 'cancellation_date'];
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(self::REASONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cancellation_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Choose why it is cancelled', 'reason.in' => 'Choose one of the listed reasons'];
    }
}
