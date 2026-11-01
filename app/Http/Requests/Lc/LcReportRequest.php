<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /reports/lc/{report} (M8). */
class LcReportRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['date_from', 'date_to', 'bank_account_id', 'segment', 'format'];
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'bank_account_id' => ['sometimes', 'integer'],
            'segment' => ['sometimes', Rule::in(['active', 'expired', 'closed'])],
            'format' => ['sometimes', Rule::in(['xlsx', 'csv', 'pdf'])],
        ];
    }
}
