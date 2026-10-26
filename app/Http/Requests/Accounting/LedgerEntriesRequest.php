<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\StrictRequest;

/** GET /ledgers/{id}/entries query (05 getLedgersIdEntries): date range (default this financial year to today), page, narration. */
class LedgerEntriesRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['date_from', 'date_to', 'page', 'narration'];
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'narration' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['date_to.after_or_equal' => 'The end date must be on or after the start date'];
    }
}
