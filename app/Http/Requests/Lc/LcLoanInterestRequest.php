<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;

/** GET /lc-loans/{id}/interest. */
class LcLoanInterestRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['as_of'];
    }

    public function rules(): array
    {
        return [
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
        ];
    }
}
