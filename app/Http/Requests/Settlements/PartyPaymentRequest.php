<?php

namespace App\Http\Requests\Settlements;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * PartyPaymentInput (05 postCustomerReceipts; backlog P6.1-BE-03). Shape only; party status, account use, balances,
 * period and back-dating are checked by PartyPaymentService. A cheque needs its number, date and bank (06 §6).
 */
class PartyPaymentRequest extends StrictRequest
{
    public const METHODS = ['cash', 'cheque', 'bank_transfer', 'beftn', 'rtgs', 'npsb', 'bkash', 'nagad', 'card'];

    private const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    /** Customer receipts; the supplier payment request (P8) overrides it. */
    protected string $partyType = 'customer';

    protected function allowedFields(): array
    {
        return ['party_id', 'date', 'amount', 'money_account_id', 'method', 'cheque_number', 'cheque_date', 'drawn_on', 'transaction_reference', 'bank_charge',
            'exchange_rate', 'narration', 'allocations', 'auto_allocate', 'confirm_backdated'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['cheque_number', 'drawn_on', 'transaction_reference', 'narration'] as $f) {
            if (is_string($this->input($f))) {
                $this->merge([$f => trim($this->input($f)) === '' ? null : trim($this->input($f))]);
            }
        }
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'party_id' => ['required', 'integer', Rule::exists('parties', 'id')->where('company_id', $c)->where('party_type', $this->partyType)->whereNull('deleted_at')],
            'date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'string', 'regex:'.self::MONEY, 'not_regex:/^0+(\.0+)?$/'],
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', $c)],
            'method' => ['required', Rule::in(self::METHODS)],
            'cheque_number' => ['required_if:method,cheque', 'nullable', 'string', 'max:20'],
            'cheque_date' => ['required_if:method,cheque', 'nullable', 'date_format:Y-m-d'],
            'drawn_on' => ['required_if:method,cheque', 'nullable', 'string', 'max:120'],
            'transaction_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'bank_charge' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'exchange_rate' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'narration' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'allocations' => ['sometimes', 'array', 'max:200'],
            'allocations.*' => ['array:document_id,amount'],
            'allocations.*.document_id' => ['required', 'integer', 'distinct'],
            'allocations.*.amount' => ['required', 'string', 'regex:'.self::MONEY, 'not_regex:/^0+(\.0+)?$/'],
            'auto_allocate' => ['sometimes', 'boolean'],
            'confirm_backdated' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.required' => 'Choose the customer',
            'party_id.exists' => 'Choose one of your customers',
            'amount.required' => 'Enter the amount received',
            'amount.not_regex' => 'Enter an amount above 0',
            'amount.regex' => 'Enter an amount with up to 2 decimals',
            'money_account_id.required' => 'Choose where the money went',
            'method.required' => 'Choose how it was paid',
            'cheque_number.required_if' => 'Enter the cheque number',
            'cheque_date.required_if' => 'Enter the cheque date',
            'drawn_on.required_if' => 'Enter the bank the cheque is drawn on',
            'allocations.*.amount.not_regex' => 'Enter an amount above 0',
        ];
    }
}
