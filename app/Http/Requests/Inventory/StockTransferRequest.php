<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * StockTransferInput (05 postStockTransfers / patchStockTransfersId; D11; backlog P11.1-BE-03). Shape only; different
 * warehouses, stock-tracked products and quantity ≤ available at the source (BR-030) are checked by StockTransferService.
 */
class StockTransferRequest extends StrictRequest
{
    private const QTY = '/^\d{1,15}(\.\d{1,3})?$/';

    protected function allowedFields(): array
    {
        return ['from_warehouse_id', 'to_warehouse_id', 'dispatch_date', 'vehicle', 'driver', 'notes', 'lines'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['vehicle', 'driver', 'notes'] as $f) {
            if (is_string($this->input($f))) {
                $this->merge([$f => trim($this->input($f)) === '' ? null : trim($this->input($f))]);
            }
        }
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();
        $wh = Rule::exists('warehouses', 'id')->where('company_id', $c);

        return [
            'from_warehouse_id' => ['required', 'integer', $wh],
            'to_warehouse_id' => ['required', 'integer', $wh],
            'dispatch_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'vehicle' => ['sometimes', 'nullable', 'string', 'max:60'],
            'driver' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:product_id,quantity'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $c)],
            'lines.*.quantity' => ['required', 'string', 'regex:'.self::QTY, 'not_regex:/^0+(\.0+)?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'from_warehouse_id.required' => 'Choose the warehouse the stock leaves',
            'to_warehouse_id.required' => 'Choose the warehouse the stock goes to',
            'lines.required' => 'Add at least one product',
            'lines.min' => 'Add at least one product',
            'lines.*.product_id.required' => 'Choose the product',
            'lines.*.product_id.exists' => 'Choose one of your products',
            'lines.*.quantity.required' => 'Enter the quantity to move',
            'lines.*.quantity.regex' => 'Enter a quantity with up to 3 decimals',
            'lines.*.quantity.not_regex' => 'The quantity must be more than 0',
        ];
    }
}
