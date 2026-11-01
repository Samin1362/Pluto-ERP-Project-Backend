<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /lc-charges/{id}/pay (ChargePayment). */
class ChargePaymentRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['paid_from_account_id', 'date', 'reference'];
    }

    public function rules(): array
    {
        return [
            'paid_from_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
