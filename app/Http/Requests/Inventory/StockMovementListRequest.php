<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /stock-movements query (05 getStockMovements, D9; backlog P4.3-BE-03): LISTQ + DATEQ + type, warehouse, product. */
class StockMovementListRequest extends StrictRequest
{
    public const TYPES = ['opening', 'purchase_in', 'sale_out', 'sales_return_in', 'purchase_return_out', 'adjustment_in', 'adjustment_out',
        'transfer_out', 'transfer_in', 'landed_cost', 'reversal'];

    public const SORTS = ['moved_on', '-moved_on'];

    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'movement_type', 'warehouse_id', 'product_id'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'movement_type' => ['sometimes', Rule::in(self::TYPES)],
            'warehouse_id' => ['sometimes', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $c)],
            'product_id' => ['sometimes', 'integer', Rule::exists('products', 'id')->where('company_id', $c)],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
