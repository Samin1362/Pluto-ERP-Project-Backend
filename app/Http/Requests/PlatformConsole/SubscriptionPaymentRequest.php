<?php

namespace App\Http\Requests\PlatformConsole;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** POST /platform/companies/{id}/payments (SubscriptionPaymentInput; PEP-503). */
class SubscriptionPaymentRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['paid_on', 'amount', 'months', 'method', 'reference', 'note'];
    }

    public function rules(): array
    {
        return [
            'paid_on' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'months' => ['required', 'integer', 'between:1,36'],
            'method' => ['required', Rule::in(['bank', 'bkash', 'nagad', 'cash', 'card', 'other'])],
            'reference' => ['sometimes', 'nullable', 'string', 'max:80'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['amount.regex' => 'Enter the amount received, like 3000', 'amount.not_regex' => 'The amount must be more than 0', 'months.between' => 'Between 1 and 36 months'];
    }
}
