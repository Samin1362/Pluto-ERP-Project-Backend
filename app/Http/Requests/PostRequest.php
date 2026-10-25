<?php

namespace App\Http\Requests;

use App\Http\Requests\Settlements\PartyPaymentRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * PostRequest (05): confirm_backdated for dates older than the back-date window (BR-053), acknowledge_warnings for
 * warnings the user has seen, and `receipt` — cash received with a sales invoice (CashReceiptInput, R-28; P6.1-BE-04):
 * the invoice and its RV post in one transaction. Only sales invoices take a receipt.
 */
class PostRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['confirm_backdated', 'acknowledge_warnings', 'receipt'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'confirm_backdated' => ['sometimes', 'boolean'],
            'acknowledge_warnings' => ['sometimes', 'array', 'max:20'],
            'acknowledge_warnings.*' => ['string', 'max:40'],
            'receipt' => ['sometimes', 'nullable', 'array:money_account_id,method,amount,transaction_reference'],
            'receipt.money_account_id' => ['required_with:receipt', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', $c)],
            'receipt.method' => ['required_with:receipt', Rule::in(PartyPaymentRequest::METHODS)],
            'receipt.amount' => ['required_with:receipt', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'receipt.transaction_reference' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return ['receipt.money_account_id.required_with' => 'Choose where the cash went', 'receipt.method.required_with' => 'Choose how it was paid',
            'receipt.amount.required_with' => 'Enter the amount received'];
    }
}
