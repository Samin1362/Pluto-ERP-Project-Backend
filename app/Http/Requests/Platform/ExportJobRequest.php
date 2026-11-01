<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** POST /export-jobs (05 ExportJobCreate; backlog P14.1-BE-05). Sources and format combinations are checked by ExportJobService. */
class ExportJobRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['kind', 'format', 'period', 'reports', 'source', 'filters'];
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['export_pack', 'report', 'list'])],
            'format' => ['required', Rule::in(['xlsx', 'pdf', 'both', 'csv'])],
            'period' => ['sometimes', 'nullable', 'date_format:Y-m'],
            'reports' => ['sometimes', 'array', 'min:1', 'max:20'],
            'reports.*' => ['string', 'max:60'],
            'source' => ['sometimes', 'nullable', 'string', 'max:60', 'required_if:kind,report,list'],
            'filters' => ['sometimes', 'nullable', 'array'],
            'filters.*' => ['nullable', fn (string $attribute, mixed $value, \Closure $fail) => is_array($value) ? $fail('Each filter is one value') : null],
        ];
    }

    public function messages(): array
    {
        return ['period.date_format' => 'The period is a month: YYYY-MM', 'source.required_if' => 'Name the report or list to export'];
    }
}
