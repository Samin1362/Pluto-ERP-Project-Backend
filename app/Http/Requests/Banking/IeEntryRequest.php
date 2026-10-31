<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** IeEntryInput (05 postIeEntries; I3, I6; backlog P13.1-BE-03; BR-054): amount > 0, a category and a money account. */
class IeEntryRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['kind', 'amount', 'date', 'category_id', 'money_account_id', 'method', 'payee_text', 'reference', 'tax_rule_id', 'notes',
            'attachment_ids', 'recurrence', 'confirm_backdated'];
    }

    public function rules(): array
    {
        $company = app(CurrentCompany::class)->id();

        return [
            'kind' => ['required', Rule::in(['income', 'expense'])],
            'amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'date' => ['required', 'date_format:Y-m-d'],
            'category_id' => ['required', 'integer', Rule::exists('ie_categories', 'id')->where('company_id', $company)->where('is_active', true)],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', $company)],
            'method' => ['required', Rule::in(['cash', 'cheque', 'bank_transfer', 'beftn', 'rtgs', 'npsb', 'bkash', 'nagad', 'card'])],
            'payee_text' => ['sometimes', 'nullable', 'string', 'max:150'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'tax_rule_id' => ['sometimes', 'nullable', 'integer', Rule::exists('tax_rules', 'id')->where('company_id', $company)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'attachment_ids' => ['sometimes', 'array', 'max:10'],
            'attachment_ids.*' => ['integer', 'distinct'],
            'recurrence' => ['sometimes', 'nullable', 'array:frequency,next_run_on,ends_type,ends_after_count,ends_on'],
            'recurrence.frequency' => ['required_with:recurrence', Rule::in(['weekly', 'monthly', 'quarterly', 'yearly'])],
            'recurrence.next_run_on' => ['required_with:recurrence', 'date_format:Y-m-d'],
            'recurrence.ends_type' => ['required_with:recurrence', Rule::in(['never', 'after_count', 'on_date'])],
            'recurrence.ends_after_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'recurrence.ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'confirm_backdated' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['amount.required' => 'Enter an amount greater than zero', 'amount.not_regex' => 'Enter an amount greater than zero',
            'amount.regex' => 'Enter the amount in taka, e.g. 1500.00', 'category_id.required' => 'Choose a category', 'category_id.exists' => 'Choose an active category',
            'money_account_id.required' => 'Choose the cash or bank account', 'method.required' => 'Choose how it was paid'];
    }
}
