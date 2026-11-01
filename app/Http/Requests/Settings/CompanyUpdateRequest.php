<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** PATCH /company (CompanyUpdate; K2). */
class CompanyUpdateRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['legal_name', 'trading_name', 'business_type', 'trade_licence_no', 'tax_registration_no', 'incorporated_on', 'registered_address', 'phone', 'email', 'website'];
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['sometimes', 'string', 'max:190'],
            'trading_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'business_type' => ['sometimes', Rule::in(['private_limited', 'sole_proprietorship', 'partnership', 'public_limited'])],
            'trade_licence_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tax_registration_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'incorporated_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'registered_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'website' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }

    public function messages(): array
    {
        return [
            'legal_name.max' => 'Use at most 190 characters',
            'incorporated_on.before_or_equal' => 'The date cannot be in the future',
        ];
    }
}
