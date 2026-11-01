<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /tax-rules, PATCH /tax-rules/{id} (TaxRuleInput; K6; BR-036: the rate is entered by the user). */
class TaxRuleRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['name', 'rate', 'applies_to', 'ledger_id', 'create_ledger', 'is_recoverable', 'is_active'];
    }

    public function rules(): array
    {
        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:80', Rule::unique('tax_rules', 'name')->where('company_id', app(CurrentCompany::class)->id())->ignore($this->route('id'))],
            'rate' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'decimal:0,4', 'min:0', 'max:100'],
            'applies_to' => [$this->isMethod('POST') ? 'required' : 'sometimes', Rule::in(['sales', 'purchases', 'both'])],
            'ledger_id' => ['sometimes', 'nullable', 'integer', Rule::exists('ledgers', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'create_ledger' => ['sometimes', 'boolean'],
            'is_recoverable' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Another tax rule has this name',
            'rate.required' => 'Enter the rate',
            'rate.max' => 'A rate is at most 100 %',
            'rate.decimal' => 'Use up to 4 decimals',
        ];
    }
}
