<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** LedgerInput (05 postLedgers / patchLedgersId; backlog P7.1-BE-02). PATCH changes only the fields sent. */
class LedgerRequest extends StrictRequest
{
    private const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    protected function allowedFields(): array
    {
        return ['name', 'account_group_id', 'code', 'description', 'track_bills', 'show_in_cash_vouchers', 'opening_balance', 'opening_side'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'code', 'description'] as $f) {
            if (is_string($this->input($f))) {
                $this->merge([$f => trim($this->input($f)) === '' ? null : trim($this->input($f))]);
            }
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:150'],
            'account_group_id' => [$required, 'integer', Rule::exists('account_groups', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'code' => ['sometimes', $this->isMethod('PATCH') ? 'required' : 'nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9.\-]*$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'track_bills' => ['sometimes', 'boolean'],
            'show_in_cash_vouchers' => ['sometimes', 'boolean'],
            'opening_balance' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'opening_side' => ['sometimes', Rule::in(['dr', 'cr'])],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter the ledger name',
            'account_group_id.required' => 'Choose the group',
            'account_group_id.exists' => 'Choose a group of this company',
            'code.regex' => 'Use letters, digits, dots or dashes',
            'opening_balance.regex' => 'Enter an amount with up to 2 decimals; choose Dr or Cr for the side',
        ];
    }
}
