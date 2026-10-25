<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\StrictRequest;
use App\Models\MoneyAccount;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * MoneyAccountInput (05 postMoneyAccounts / patchMoneyAccountsId; backlog P6.1-BE-01). POST needs the type; bank and
 * cash-credit accounts also need the bank, account name and number. The contract marks those three required for every
 * type (contract gap, noted on PEP-165): a cash box has no bank, so they are required only where a bank exists. PATCH
 * changes only the fields sent; `is_active` is accepted on PATCH for the task's "active" switch (also a noted gap).
 */
class MoneyAccountRequest extends StrictRequest
{
    private const MONEY = '/^-?\d{1,16}(\.\d{1,2})?$/';

    protected function allowedFields(): array
    {
        return ['account_type', 'overdraft_limit', 'name', 'bank_name', 'branch_name', 'account_name', 'account_number', 'routing_number', 'opening_balance',
            'opening_date', 'custodian_user_id', 'use_for_receipts', 'use_for_payments', 'show_on_invoices', ...($this->isMethod('PATCH') ? ['is_active'] : [])];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'bank_name', 'branch_name', 'account_name', 'account_number', 'routing_number'] as $f) {
            if (is_string($this->input($f))) {
                $this->merge([$f => trim($this->input($f)) === '' ? null : trim($this->input($f))]);
            }
        }
    }

    public function rules(): array
    {
        $patch = $this->isMethod('PATCH');
        $bank = $patch ? 'sometimes' : 'required_if:account_type,bank,cash_credit';
        $members = Rule::exists('company_users', 'user_id')->where('company_id', app(CurrentCompany::class)->id())->where('status', 'active');

        return [
            'account_type' => [$patch ? 'sometimes' : 'required', Rule::in(MoneyAccount::TYPES)],
            'overdraft_limit' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY, 'not_regex:/^-/'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'bank_name' => [$bank, 'nullable', 'string', 'max:120'],
            'branch_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'account_name' => [$bank, 'nullable', 'string', 'max:150'],
            'account_number' => [$bank, 'nullable', 'string', 'max:40'],
            'routing_number' => ['sometimes', 'nullable', 'string', 'regex:/^\d{9}$/'],
            'opening_balance' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'opening_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'custodian_user_id' => ['sometimes', 'nullable', 'integer', $members],
            'use_for_receipts' => ['sometimes', 'boolean'],
            'use_for_payments' => ['sometimes', 'boolean'],
            'show_on_invoices' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_type.required' => 'Choose the kind of account',
            'bank_name.required_if' => 'Enter the bank',
            'account_name.required_if' => 'Enter the name on the account',
            'account_number.required_if' => 'Enter the account number',
            'routing_number.regex' => 'The routing number has 9 digits',
            'overdraft_limit.not_regex' => 'The limit cannot be negative',
            'opening_balance.regex' => 'Enter an amount with up to 2 decimals',
            'custodian_user_id.exists' => 'Choose an active member of this company',
        ];
    }
}
