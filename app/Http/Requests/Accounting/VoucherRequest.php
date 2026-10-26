<?php

namespace App\Http\Requests\Accounting;

use App\Domain\Accounting\VoucherCalculator;
use App\Http\Requests\StrictRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/** VoucherInput (05 postVouchers / putVouchersId / postVouchersCalculate; backlog P7.1-BE-04). Ledgers and accounts are checked by VoucherCalculator. */
class VoucherRequest extends StrictRequest
{
    private const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    private const PAYMENT_FIELDS = ['money_account_id', 'counter_money_account_id', 'method', 'party_id', 'payee_text', 'cheque_number', 'cheque_date', 'drawn_on',
        'transaction_reference', 'bank_charge', 'handed_to'];

    private const LINE_FIELDS = ['ledger_id', 'debit', 'credit', 'amount', 'line_narration', 'against_reference', 'allocations'];

    protected function allowedFields(): array
    {
        return ['entry_type', 'action', 'date', 'reference', 'narration', 'template_key', 'payment', 'lines', 'confirm_backdated'];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $v) {
            foreach (array_diff(array_keys((array) $this->input('payment', [])), self::PAYMENT_FIELDS) as $f) {
                $v->errors()->add("payment.{$f}", 'This field is not part of the API.');
            }
            foreach ((array) $this->input('lines', []) as $i => $line) {
                foreach (array_diff(array_keys((array) $line), self::LINE_FIELDS) as $f) {
                    $v->errors()->add("lines.{$i}.{$f}", 'This field is not part of the API.');
                }
            }
        });
    }

    public function rules(): array
    {
        $money = ['nullable', 'string', 'regex:'.self::MONEY];

        return [
            'entry_type' => ['required', Rule::in(VoucherCalculator::TYPES)],
            'action' => ['required', Rule::in(['draft', 'submit', 'post'])],
            'date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'narration' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'template_key' => ['sometimes', 'nullable', 'string', 'max:40'],
            'confirm_backdated' => ['sometimes', 'boolean'],
            'payment' => ['sometimes', 'nullable', 'array'],
            'payment.money_account_id' => ['required_with:payment', 'integer'],
            'payment.counter_money_account_id' => ['sometimes', 'nullable', 'integer'],
            'payment.method' => ['required_with:payment', Rule::in(['cash', 'cheque', 'bank_transfer', 'beftn', 'rtgs', 'npsb', 'bkash', 'nagad', 'card'])],
            'payment.party_id' => ['sometimes', 'nullable', 'integer'],
            'payment.payee_text' => ['sometimes', 'nullable', 'string', 'max:150'],
            'payment.cheque_number' => ['sometimes', 'nullable', 'string', 'max:20'],
            'payment.cheque_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'payment.drawn_on' => ['sometimes', 'nullable', 'string', 'max:120'],
            'payment.transaction_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'payment.bank_charge' => ['sometimes', ...$money],
            'payment.handed_to' => ['sometimes', 'nullable', 'string', 'max:120'],
            'lines' => ['present', 'array', 'max:200'],
            'lines.*' => ['array'],
            'lines.*.ledger_id' => ['required', 'integer'],
            'lines.*.debit' => ['sometimes', ...$money],
            'lines.*.credit' => ['sometimes', ...$money],
            'lines.*.amount' => ['sometimes', ...$money],
            'lines.*.line_narration' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines.*.against_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
            'lines.*.allocations' => ['sometimes', 'array'],
            'lines.*.allocations.*.document_id' => ['required', 'integer'],
            'lines.*.allocations.*.amount' => ['required', 'string', 'regex:'.self::MONEY],
        ];
    }

    public function messages(): array
    {
        return [
            'entry_type.in' => 'Choose journal, payment, receipt or contra',
            'payment.method.required_with' => 'Choose how the money moved',
            'lines.present' => 'Add the voucher lines',
            'lines.*.debit.regex' => 'Enter an amount with up to 2 decimals',
            'lines.*.credit.regex' => 'Enter an amount with up to 2 decimals',
            'lines.*.amount.regex' => 'Enter an amount with up to 2 decimals',
        ];
    }
}
