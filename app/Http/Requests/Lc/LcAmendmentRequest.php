<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * POST /letters-of-credit/{id}/amendments (LcAmendmentInput; BR-041). `lines` (LcAmendmentLineInput, PEP-487) carry item changes; `changes` may be empty
 * when `lines` are given. What each action needs and the quantities already taken by bills are checked by LcService.
 */
class LcAmendmentRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['requested_on', 'status', 'changes', 'other_change', 'reason', 'bank_fee', 'lines'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'requested_on' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['requested', 'issued', 'accepted'])],
            'changes' => ['present', 'array'],
            'changes.*.field_key' => ['required', 'distinct', Rule::in(['expiry_date', 'latest_shipment_date', 'amount', 'tolerance_pct', 'other'])],
            'changes.*.new_value' => ['required', 'string', 'max:120'],
            'other_change' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'bank_fee' => ['sometimes', 'regex:/^\d{1,13}(\.\d{1,2})?$/'],
            'lines' => ['sometimes', 'array', 'max:200'],
            'lines.*' => ['array:action,purchase_order_line_id,product_id,unit_id,quantity,unit_cost,description'],
            'lines.*.action' => ['required', Rule::in(['add', 'change', 'remove'])],
            'lines.*.purchase_order_line_id' => ['sometimes', 'nullable', 'integer', 'required_unless:lines.*.action,add'],
            'lines.*.product_id' => ['sometimes', 'nullable', 'integer', 'required_if:lines.*.action,add', Rule::exists('products', 'id')->where('company_id', $c)->whereNull('deleted_at')],
            'lines.*.unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('units', 'id')->where('company_id', $c)],
            'lines.*.quantity' => ['sometimes', 'nullable', 'string', 'required_if:lines.*.action,add', 'regex:/^\d{1,15}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit_cost' => ['sometimes', 'nullable', 'string', 'required_if:lines.*.action,add', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'changes.present' => 'Say what changes',
            'lines.*.purchase_order_line_id.required_unless' => 'Choose the item to change',
            'lines.*.product_id.required_if' => 'Choose an item',
            'lines.*.product_id.exists' => 'Choose one of your items',
            'lines.*.quantity.required_if' => 'Enter the quantity',
            'lines.*.quantity.regex' => 'Enter a quantity with up to 3 decimals',
            'lines.*.quantity.not_regex' => 'The quantity must be more than 0 (remove the item instead)',
            'lines.*.unit_cost.required_if' => 'Enter the unit cost',
            'lines.*.unit_cost.regex' => 'Enter a cost of 0 or more with up to 4 decimals',
        ];
    }

    /** An amendment changes at least one term or one item. */
    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $v) {
            if (is_array($this->input('changes')) && $this->input('changes') === [] && empty($this->input('lines'))) {
                $v->errors()->add('changes', 'Say what changes');
            }
        });
    }
}
