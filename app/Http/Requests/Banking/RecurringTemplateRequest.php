<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** RecurringTemplateInput (05 patchRecurringTemplatesId; I10; backlog P13.1-BE-04): pause or resume, reschedule, end. */
class RecurringTemplateRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['frequency', 'next_run_on', 'ends_type', 'ends_after_count', 'ends_on', 'is_active'];
    }

    public function rules(): array
    {
        return [
            'frequency' => ['sometimes', Rule::in(['weekly', 'monthly', 'quarterly', 'yearly'])],
            'next_run_on' => ['sometimes', 'date_format:Y-m-d'],
            'ends_type' => ['sometimes', Rule::in(['never', 'after_count', 'on_date'])],
            'ends_after_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
