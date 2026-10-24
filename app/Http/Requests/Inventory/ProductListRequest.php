<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /products query (05 getProducts, D1, backlog P3.1-BE-06): LISTQ plus type, category, warehouse, stock and active filters. */
class ProductListRequest extends StrictRequest
{
    public const SORTS = ['name', '-name', 'code', '-code', 'selling_price', '-selling_price', 'on_hand', '-on_hand'];

    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'item_type', 'category_id', 'warehouse_id', 'stock_status', 'active'];
    }

    public function rules(): array
    {
        $company = app(CurrentCompany::class)->id();

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'item_type' => ['sometimes', Rule::in(['product', 'service'])],
            'category_id' => ['sometimes', 'integer', Rule::exists('product_categories', 'id')->where('company_id', $company)],
            'warehouse_id' => ['sometimes', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $company)],
            'stock_status' => ['sometimes', Rule::in(['in_stock', 'low_stock', 'out_of_stock', 'not_tracked'])],
            'active' => ['sometimes', Rule::in(['true', 'false', 'all'])],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
