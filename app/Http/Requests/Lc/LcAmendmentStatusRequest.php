<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** PATCH /lc-amendments/{id} (LcAmendmentStatus). */
class LcAmendmentStatusRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['status', 'date'];
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['issued', 'accepted', 'refused'])],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
