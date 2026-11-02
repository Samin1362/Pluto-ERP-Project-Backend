<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /letters-of-credit/{id}/payments (LcPaymentInput; BR-042–BR-044; financed_by + loan: BR-067). */
class LcPaymentRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['payment_kind', 'payment_date', 'amount_foreign', 'bank_rate', 'money_account_id', 'margin_applied', 'lc_shipment_id', 'purchase_bill_id', 'bank_reference', 'description', 'financed_by', 'loan'];
    }

    public function rules(): array
    {
        return [
            'payment_kind' => ['sometimes', Rule::in(['sight_payment', 'usance_acceptance', 'usance_settlement'])],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'amount_foreign' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'bank_rate' => ['required', 'regex:/^\d{1,12}(\.\d{1,6})?$/', 'not_in:0'],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'margin_applied' => ['sometimes', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lc_shipment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lc_shipments', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'purchase_bill_id' => ['sometimes', 'nullable', 'integer', Rule::exists('purchase_bills', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'bank_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:150'],
            'financed_by' => ['sometimes', Rule::in(['own', 'pad', 'ltr'])],
            'loan' => ['sometimes', 'nullable', 'array:loan_kind,interest_rate_pct,day_count,tenor_days,start_date', 'prohibited_unless:financed_by,pad,ltr'],
            'loan.loan_kind' => ['sometimes', Rule::in(['pad', 'ltr'])],
            'loan.interest_rate_pct' => ['sometimes', 'regex:/^\d{1,3}(\.\d{1,4})?$/', 'lte:100'],
            'loan.day_count' => ['sometimes', Rule::in(['360', '365', 360, 365])],
            'loan.tenor_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'loan.start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'bank_rate.required' => 'Enter the rate the bank applied',
            'amount_foreign.required' => 'Enter the amount paid in the LC currency',
        ];
    }
}
