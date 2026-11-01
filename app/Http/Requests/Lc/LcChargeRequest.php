<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /lc-charges (LcChargeInput; 06 §12.2–12.3; BR-044, BR-045, BR-047). */
class LcChargeRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['letter_of_credit_id', 'charge_type', 'charge_date', 'amount', 'paid_to', 'payee_party_id', 'status', 'paid_from_account_id', 'include_in_landed_cost', 'reference', 'lc_shipment_id', 'lc_amendment_id', 'tax_rule_id'];
    }

    public function rules(): array
    {
        return [
            'letter_of_credit_id' => ['required', 'integer', Rule::exists('letters_of_credit', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'charge_type' => ['required', Rule::in(['margin_deposit', 'opening_commission', 'amendment_fee', 'swift_postage', 'stamp_documentation', 'acceptance_commission', 'discrepancy_fee', 'marine_insurance', 'cf_agent_fee', 'port_handling', 'customs_duty_taxes', 'inland_transport', 'other'])],
            'charge_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'paid_to' => ['sometimes', 'nullable', 'string', 'max:150'],
            'payee_party_id' => ['sometimes', 'nullable', 'integer', Rule::exists('parties', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'status' => ['required', Rule::in(['unpaid', 'paid'])],
            'paid_from_account_id' => ['sometimes', 'nullable', 'integer', 'required_if:status,paid', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'include_in_landed_cost' => ['sometimes', 'boolean'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'lc_shipment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lc_shipments', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'lc_amendment_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lc_amendments', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'tax_rule_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tax_rules', 'id')->where('company_id', app(CurrentCompany::class)->id())],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Enter the amount',
            'amount.not_in' => 'The amount must be above 0',
            'paid_from_account_id.required_if' => 'Choose the account it was paid from',
        ];
    }
}
