<?php

namespace App\Http\Requests\Settlements;

/** PartyPaymentInput for POST /supplier-payments (F5; backlog P8.1-BE-01): the party must be one of the company's suppliers. */
class SupplierPaymentRequest extends PartyPaymentRequest
{
    protected string $partyType = 'supplier';

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'party_id.required' => 'Choose the supplier',
            'party_id.exists' => 'Choose one of your suppliers',
            'amount.required' => 'Enter the amount paid',
            'money_account_id.required' => 'Choose the account it is paid from',
            'drawn_on.required_if' => 'Enter the bank the cheque is drawn on',
        ];
    }
}
