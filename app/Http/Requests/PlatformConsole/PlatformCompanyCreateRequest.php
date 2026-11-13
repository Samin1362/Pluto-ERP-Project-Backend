<?php

namespace App\Http\Requests\PlatformConsole;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** POST /platform/companies (PlatformCompanyCreate; PEP-483). */
class PlatformCompanyCreateRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['legal_name', 'trading_name', 'business_type', 'fy_start_month', 'base_currency_code', 'owner_email', 'owner_name'];
    }

    public function rules(): array
    {
        return [
            'legal_name' => ['required', 'string', 'max:190'],
            'trading_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'business_type' => ['required', Rule::in(['private_limited', 'sole_proprietorship', 'partnership', 'public_limited'])],
            'fy_start_month' => ['sometimes', 'integer', 'between:1,12'],
            'base_currency_code' => ['sometimes', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'owner_email' => ['required', 'email', 'max:190'],
            'owner_name' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'legal_name.required' => 'Enter the company name',
            'business_type.required' => 'Choose the type of business',
            'owner_email.required' => 'Enter the e-mail of the person who will own this company',
        ];
    }
}
