<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\StrictRequest;

/** PostRequest for POST /vouchers/{id}/post (05): a cash-sale `receipt` belongs to sales invoices, not vouchers. */
class VoucherPostRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['confirm_backdated', 'acknowledge_warnings', 'receipt'];
    }

    public function rules(): array
    {
        return [
            'confirm_backdated' => ['sometimes', 'boolean'],
            'acknowledge_warnings' => ['sometimes', 'array'],
            'acknowledge_warnings.*' => ['string', 'max:40'],
            'receipt' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['receipt.prohibited' => 'A receipt is recorded with a sales invoice; enter a receipt voucher instead'];
    }
}
