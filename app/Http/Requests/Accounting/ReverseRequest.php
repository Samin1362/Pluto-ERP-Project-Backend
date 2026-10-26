<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\StrictRequest;

/** ReverseRequest (05 postVouchersIdReverse): the reversal's date and the reason (audited, shown on the entry). */
class ReverseRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['reversal_date', 'reason'];
    }

    public function rules(): array
    {
        return [
            'reversal_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['reversal_date.required' => 'Choose the reversal date', 'reason.required' => 'Say why the voucher is reversed'];
    }
}
