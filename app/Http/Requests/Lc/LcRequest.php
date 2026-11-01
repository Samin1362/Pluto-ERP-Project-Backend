<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /letters-of-credit, PUT /letters-of-credit/{id} (LetterOfCreditInput; M3). Only supplier, amount, issuing bank and expiry are needed to submit (BR-039). */
class LcRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['party_id', 'purchase_order_id', 'intended_import', 'goods_description', 'quantity', 'quantity_unit', 'import_authorization_ref', 'lc_type', 'tenor', 'usance_days', 'incoterm', 'currency_code', 'amount', 'tolerance_pct', 'planning_rate', 'partial_shipments', 'transhipment', 'confirmation_required', 'issuing_bank_account_id', 'advising_bank', 'margin_pct', 'margin_account_id', 'application_date', 'latest_shipment_date', 'expiry_date', 'presentation_days', 'document_template_id', 'documents', 'record_shipments', 'shipment'];
    }

    public function rules(): array
    {
        return [
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'purchase_order_id' => ['sometimes', 'nullable', 'integer', Rule::exists('purchase_orders', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'intended_import' => ['sometimes', 'nullable', 'string', 'max:255'],
            'goods_description' => ['required', 'string', 'max:500'],
            'quantity' => ['sometimes', 'nullable', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
            'quantity_unit' => ['sometimes', 'nullable', 'string', 'max:20'],
            'import_authorization_ref' => ['sometimes', 'nullable', 'string', 'max:60'],
            'lc_type' => ['sometimes', Rule::in(['irrevocable', 'irrevocable_transferable', 'standby'])],
            'tenor' => ['required', Rule::in(['sight', 'usance'])],
            'usance_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:720', 'required_if:tenor,usance'],
            'incoterm' => ['required', 'string', 'max:40'],
            'currency_code' => ['required', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'amount' => ['required', 'regex:/^\d{1,13}(\.\d{1,2})?$/', 'not_in:0,0.0,0.00'],
            'tolerance_pct' => ['sometimes', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'planning_rate' => ['required', 'regex:/^\d{1,12}(\.\d{1,6})?$/', 'not_in:0'],
            'partial_shipments' => ['sometimes', 'boolean'],
            'transhipment' => ['sometimes', 'boolean'],
            'confirmation_required' => ['sometimes', 'boolean'],
            'issuing_bank_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'advising_bank' => ['sometimes', 'nullable', 'string', 'max:190'],
            'margin_pct' => ['sometimes', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'margin_account_id' => ['sometimes', 'nullable', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'application_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'latest_shipment_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expiry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:latest_shipment_date'],
            'presentation_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'document_template_id' => ['sometimes', 'nullable', 'integer', Rule::exists('lc_document_templates', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'documents' => ['sometimes', 'array'],
            'documents.*.name' => ['required', 'string', 'max:120'],
            'documents.*.provided_by' => ['required', 'string', 'max:80'],
            'documents.*.copies' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'documents.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'documents.*.is_required' => ['sometimes', 'boolean'],
            'record_shipments' => ['sometimes', 'boolean'],
            'shipment' => ['sometimes', 'nullable', 'array'],
            'shipment.etd' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'shipment.eta' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'shipment.port_of_loading' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shipment.port_of_discharge' => ['sometimes', 'nullable', 'string', 'max:120'],
            'shipment.mode' => ['sometimes', Rule::in(['sea', 'air', 'road'])],
            'shipment.quantity' => ['sometimes', 'nullable', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
            'shipment.value_foreign' => ['sometimes', 'nullable', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.required' => 'Choose the supplier',
            'goods_description.required' => 'Describe the goods',
            'amount.required' => 'Enter the LC amount',
            'amount.not_in' => 'The amount must be above 0',
            'planning_rate.required' => 'Enter a planning exchange rate',
            'usance_days.required_if' => 'Enter the usance days',
            'expiry_date.after_or_equal' => 'Expiry cannot be before the latest shipment date',
            'incoterm.required' => 'Choose the Incoterm',
        ];
    }
}
