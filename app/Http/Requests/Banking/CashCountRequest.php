<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** CashCountInput (05 postCashCounts; G2; backlog P12.1-BE-03): the cash account, when, and the notes and coins counted. */
class CashCountRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['money_account_id', 'counted_at', 'denominations', 'note'];
    }

    public function rules(): array
    {
        return [
            'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'counted_at' => ['required', 'date'],
            'denominations' => ['required', 'array', 'min:1', 'max:30'],
            'denominations.*' => ['array:denomination,count'],
            'denominations.*.denomination' => ['required', 'string', 'regex:/^\d{1,7}(\.\d{1,2})?$/', 'not_regex:/^0+(\.0+)?$/', 'distinct'],
            'denominations.*.count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['money_account_id.required' => 'Choose the cash account counted', 'denominations.required' => 'Enter what you counted',
            'denominations.*.denomination.distinct' => 'Each note or coin appears once', 'denominations.*.count.min' => 'A count cannot be negative'];
    }
}
