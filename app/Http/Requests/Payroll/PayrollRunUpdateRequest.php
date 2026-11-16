<?php

namespace App\Http\Requests\Payroll;

use App\Http\Requests\StrictRequest;

/** PayrollRunUpdate (PEP-521): a draft sheet's pay date, notes and line changes. */
class PayrollRunUpdateRequest extends StrictRequest
{
    private const MONEY = '/^\d{1,13}(\.\d{1,2})?$/';

    protected function allowedFields(): array
    {
        return ['pay_date', 'notes', 'lines'];
    }

    public function rules(): array
    {
        return [
            'pay_date' => ['sometimes', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'lines' => ['sometimes', 'array', 'max:1000'],
            'lines.*' => ['array:id,overtime,other_earnings,deductions,advance_recovery,note'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.overtime' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'lines.*.other_earnings' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'lines.*.deductions' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'lines.*.advance_recovery' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'lines.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        $m = 'Enter an amount of 0 or more with up to 2 decimals';

        return ['lines.*.overtime.regex' => $m, 'lines.*.other_earnings.regex' => $m, 'lines.*.deductions.regex' => $m, 'lines.*.advance_recovery.regex' => $m];
    }
}
