<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /letters-of-credit/{id}/payments (LcPaymentInput; BR-042–BR-044). */
class LcPaymentRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['payment_kind', 'payment_date', 'amount_foreign', 'bank_rate', 'money_account_id', 'margin_applied', 'lc_shipment_id', 'purchase_bill_id', 'bank_reference', 'description'];
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
