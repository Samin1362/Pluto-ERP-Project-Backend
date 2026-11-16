<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** RecurringInvoiceCreate (PEP-517): repeat an invoice. */
class RecurringInvoiceCreateRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['frequency', 'next_run_on', 'ends_type', 'ends_after_count', 'ends_on'];
    }

    public function rules(): array
    {
        return [
            'frequency' => ['required', Rule::in(['weekly', 'monthly', 'quarterly', 'yearly'])],
            'next_run_on' => ['required', 'date_format:Y-m-d'],
            'ends_type' => ['required', Rule::in(['never', 'after_count', 'on_date'])],
            'ends_after_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:next_run_on'],
        ];
    }
}
