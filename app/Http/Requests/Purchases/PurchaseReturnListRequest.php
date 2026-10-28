<?php

namespace App\Http\Requests\Purchases;

use App\Domain\Purchases\PurchaseReturnService;
use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /purchase-returns and GET /debit-notes queries (05; F13; backlog P10.4): LISTQ + DATEQ + supplier, and reason (returns) or status/usage (notes). */
class PurchaseReturnListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return $this->is('*/debit-notes') ? ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'party_id', 'status', 'usage']
            : ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'party_id', 'reason', 'status'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();
        $notes = $this->is('*/debit-notes');
        $date = $notes ? 'note_date' : 'return_date';
        $amount = $notes ? 'amount' : 'total';

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'party_id' => ['sometimes', 'integer', Rule::exists('parties', 'id')->where('company_id', $c)->where('party_type', 'supplier')],
            'reason' => ['sometimes', Rule::in(PurchaseReturnService::REASONS)],
            'status' => ['sometimes', Rule::in(['draft', 'posted', 'cancelled'])],
            'usage' => ['sometimes', Rule::in(['apply', 'credit', 'refund'])],
            'sort' => ['sometimes', Rule::in([$date, "-{$date}", 'number', '-number', $amount, "-{$amount}"])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
