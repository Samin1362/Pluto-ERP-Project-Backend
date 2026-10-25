<?php

namespace App\Http\Requests\Settlements;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** AllocationCreate (05 postAllocations; R-08; backlog P6.1-BE-02). Shape only; party, balances and period are checked by AllocationService. */
class AllocationRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['journal_line_id', 'document_type', 'document_id', 'amount', 'allocated_on'];
    }

    public function rules(): array
    {
        return [
            'journal_line_id' => ['required', 'integer', 'min:1'],
            'document_type' => ['required', Rule::in(['sales_invoice', 'purchase_bill'])],
            'document_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'allocated_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return ['amount.regex' => 'Enter an amount with up to 2 decimals', 'amount.not_regex' => 'Enter an amount above 0'];
    }
}
