<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /stock-adjustments and GET /stock-transfers queries (05; D10, D11; backlog P11.1-BE-02/03): LISTQ + DATEQ + status, warehouse. */
class StockDocumentListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'status', 'warehouse_id'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();
        $transfers = $this->is('*/stock-transfers');
        $date = $transfers ? 'dispatch_date' : 'adjustment_date';

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['sometimes', Rule::in($transfers ? ['draft', 'dispatched', 'received', 'cancelled'] : ['draft', 'posted', 'reversed'])],
            'warehouse_id' => ['sometimes', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $c)],
            'sort' => ['sometimes', Rule::in([$date, "-{$date}", 'number', '-number', 'created_at', '-created_at'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
