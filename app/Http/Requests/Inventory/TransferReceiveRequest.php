<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;

/** TransferReceive (05 postStockTransfersIdReceive; D11): the arrival date and, optionally, what arrived per product (default: all sent). */
class TransferReceiveRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['received_date', 'lines'];
    }

    public function rules(): array
    {
        return [
            'received_date' => ['required', 'date_format:Y-m-d'],
            'lines' => ['sometimes', 'array', 'max:200'],
            'lines.*' => ['array:product_id,quantity'],
            'lines.*.product_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'received_date.required' => 'Enter the date the goods arrived',
            'lines.*.quantity.required' => 'Enter the quantity received',
            'lines.*.quantity.regex' => 'Enter a quantity of 0 or more, with up to 3 decimals',
        ];
    }
}
