<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /purchase-orders query (05 getPurchaseOrders, F11; backlog P10.2-BE-01): LISTQ + DATEQ + status, supplier and currency. */
class PurchaseOrderListRequest extends StrictRequest
{
    public const SORTS = ['order_date', '-order_date', 'number', '-number', 'expected_date', '-expected_date', 'total', '-total'];

    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'status', 'party_id', 'currency'];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('currency'))) {
            $this->merge(['currency' => strtoupper($this->input('currency'))]);
        }
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['sometimes', Rule::in(['draft', 'sent', 'partly_received', 'received', 'closed', 'cancelled', 'open'])],
            'party_id' => ['sometimes', 'integer', Rule::exists('parties', 'id')->where('company_id', $c)->where('party_type', 'supplier')],
            'currency' => ['sometimes', 'string', 'size:3'],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
