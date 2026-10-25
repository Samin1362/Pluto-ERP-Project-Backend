<?php

namespace App\Http\Requests\Parties;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** StatementSendRequest (05 postPartiesStatementsSend; backlog P6.1-BE-05): which parties, which period, with aging. */
class StatementSendRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['party_ids', 'date_from', 'date_to', 'include_aging'];
    }

    public function rules(): array
    {
        return [
            'party_ids' => ['required', 'array', 'min:1', 'max:200'],
            'party_ids.*' => ['integer', 'distinct', Rule::exists('parties', 'id')->where('company_id', app(CurrentCompany::class)->id())->whereNull('deleted_at')],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'include_aging' => ['sometimes', 'boolean'],
        ];
    }
}
