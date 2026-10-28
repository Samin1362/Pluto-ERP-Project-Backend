<?php

namespace App\Http\Requests\Sales;

use App\Domain\Sales\SalesReturnService;
use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /sales-returns and GET /credit-notes queries (05; E15; backlog P10.3): LISTQ + DATEQ + customer, and reason (returns) or status/usage (notes). */
class SalesReturnListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return $this->is('*/credit-notes') ? ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'party_id', 'status', 'usage']
            : ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'party_id', 'reason', 'status'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();
        $date = $this->is('*/credit-notes') ? 'note_date' : 'return_date';

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'party_id' => ['sometimes', 'integer', Rule::exists('parties', 'id')->where('company_id', $c)->where('party_type', 'customer')],
            'reason' => ['sometimes', Rule::in(SalesReturnService::REASONS)],
            'status' => ['sometimes', Rule::in(['draft', 'posted', 'cancelled'])],
            'usage' => ['sometimes', Rule::in(['apply', 'credit', 'refund'])],
            'sort' => ['sometimes', Rule::in([$date, "-{$date}", 'number', '-number', $this->is('*/credit-notes') ? 'amount' : 'total', $this->is('*/credit-notes') ? '-amount' : '-total'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
