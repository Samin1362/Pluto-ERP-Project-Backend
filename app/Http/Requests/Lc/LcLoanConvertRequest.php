<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;

/** POST /lc-loans/{id}/convert (LcLoanConvert; BR-067). */
class LcLoanConvertRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['converted_on', 'interest_rate_pct', 'tenor_days', 'notes'];
    }

    public function rules(): array
    {
        return [
            'converted_on' => ['required', 'date_format:Y-m-d'],
            'interest_rate_pct' => ['required', 'regex:/^\d{1,3}(\.\d{1,4})?$/', 'lte:100'],
            'tenor_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
