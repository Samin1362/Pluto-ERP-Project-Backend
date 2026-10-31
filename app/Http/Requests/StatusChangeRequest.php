<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * StatusChange (05): set a document status by hand, with an optional reason kept in the audit log. Quotations take
 * accepted / declined / expired, purchase orders closed / cancelled (06 §2); each subclass names its own.
 */
abstract class StatusChangeRequest extends StrictRequest
{
    /** @return list<string> */
    abstract protected function statuses(): array;

    protected function allowedFields(): array
    {
        return ['status', 'reason'];
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in($this->statuses())],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['status.required' => 'Choose the new status', 'status.in' => 'Choose one of: '.implode(', ', $this->statuses())];
    }
}
