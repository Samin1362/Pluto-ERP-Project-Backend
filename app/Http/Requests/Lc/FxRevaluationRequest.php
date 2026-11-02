<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;

/** POST /fx-revaluations(/preview) (FxRevaluationRequest; BR-068). */
class FxRevaluationRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['revaluation_date', 'rates', 'note'];
    }

    public function rules(): array
    {
        return [
            'revaluation_date' => ['required', 'date_format:Y-m-d'],
            'rates' => ['required', 'array', 'min:1', 'max:20'],
            'rates.*.currency_code' => ['required', 'string', 'size:3', 'distinct', 'not_in:BDT,bdt'],
            'rates.*.rate' => ['required', 'regex:/^\d{1,12}(\.\d{1,6})?$/', 'not_in:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
