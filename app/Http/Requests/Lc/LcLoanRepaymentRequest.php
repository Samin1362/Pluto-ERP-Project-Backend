<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /lc-loans/{id}/repayments (LcLoanRepaymentInput; BR-067). */
class LcLoanRepaymentRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['repayment_date', 'principal_amount', 'money_account_id', 'reference'];
    }

    public function rules(): array
    {
        return [
            'repayment_date' => ['required', 'date_format:Y-m-d'],
            'principal_amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
