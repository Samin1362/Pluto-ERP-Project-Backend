<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** LowStockOrderRequest (05 postPurchaseOrdersFromLowStock, D12; backlog P10.2-BE-01): rows {product_id, warehouse_id, quantity?} and the order date. */
class LowStockOrderRequest extends StrictRequest
{
    private const QTY = '/^\d{1,15}(\.\d{1,3})?$/';

    protected function allowedFields(): array
    {
        return ['rows', 'order_date'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $c);

        return [
            'order_date' => ['required', 'date_format:Y-m-d'],
            'rows' => ['required', 'array', 'min:1', 'max:200'],
            'rows.*' => ['array:product_id,warehouse_id,quantity'],
            'rows.*.product_id' => ['required', 'integer', $exists('products')->whereNull('deleted_at')],
            'rows.*.warehouse_id' => ['required', 'integer', $exists('warehouses')],
            'rows.*.quantity' => ['sometimes', 'nullable', 'string', 'regex:'.self::QTY, 'not_regex:/^0+(\.0+)?$/'],
        ];
    }

    public function messages(): array
    {
        return ['rows.required' => 'Choose at least one item to order', 'rows.min' => 'Choose at least one item to order',
            'rows.*.quantity.not_regex' => 'The quantity must be more than 0'];
    }
}
