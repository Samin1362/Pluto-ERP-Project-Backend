<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /quotations query (05 getQuotations, E13; backlog P10.1-BE-01): LISTQ + DATEQ + status and customer. */
class QuotationListRequest extends StrictRequest
{
    public const SORTS = ['quotation_date', '-quotation_date', 'number', '-number', 'valid_until', '-valid_until', 'total', '-total'];

    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'status', 'party_id'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['sometimes', Rule::in(['draft', 'sent', 'accepted', 'declined', 'expired', 'converted'])],
            'party_id' => ['sometimes', 'integer', Rule::exists('parties', 'id')->where('company_id', $c)->where('party_type', 'customer')],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
