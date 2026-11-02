<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /bank-facilities, PUT /bank-facilities/{id} (BankFacilityInput; BR-066). */
class BankFacilityRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['money_account_id', 'lc_limit', 'loan_limit', 'limit_action', 'interest_rate_pct', 'day_count', 'loan_tenor_days', 'notes', 'is_active'];
    }

    public function rules(): array
    {
        return [
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'lc_limit' => ['sometimes', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'loan_limit' => ['sometimes', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'limit_action' => ['sometimes', Rule::in(['warn', 'block'])],
            'interest_rate_pct' => ['sometimes', 'regex:/^\d{1,3}(\.\d{1,4})?$/', 'lte:100'],
            'day_count' => ['sometimes', Rule::in(['360', '365', 360, 365])],
            'loan_tenor_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
