<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\StockAdjustmentService;
use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * StockAdjustmentInput (05 postStockAdjustments / patchStockAdjustmentsId; D10; backlog P11.1-BE-02). Shape only: the
 * warehouse is the company's, each line a product with a counted quantity ≥ 0 (06 §11.3). Stock-tracked, active and
 * one line per product are checked by StockAdjustmentService.
 */
class StockAdjustmentRequest extends StrictRequest
{
    private const QTY = '/^\d{1,15}(\.\d{1,3})?$/';

    protected function allowedFields(): array
    {
        return ['adjustment_date', 'warehouse_id', 'reason', 'notes', 'lines'];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('notes'))) {
            $this->merge(['notes' => trim($this->input('notes')) === '' ? null : trim($this->input('notes'))]);
        }
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'adjustment_date' => ['required', 'date_format:Y-m-d'],
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('company_id', $c)],
            'reason' => ['required', Rule::in(StockAdjustmentService::REASONS)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*' => ['array:product_id,counted_qty'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', $c)],
            'lines.*.counted_qty' => ['required', 'string', 'regex:'.self::QTY],
        ];
    }

    public function messages(): array
    {
        return [
            'adjustment_date.required' => 'Enter the date of the count',
            'warehouse_id.required' => 'Choose the warehouse counted',
            'reason.required' => 'Choose why stock is adjusted',
            'reason.in' => 'Choose one of the listed reasons',
            'lines.required' => 'Add at least one product',
            'lines.min' => 'Add at least one product',
            'lines.*.product_id.required' => 'Choose the product',
            'lines.*.product_id.exists' => 'Choose one of your products',
            'lines.*.counted_qty.required' => 'Enter the counted quantity',
            'lines.*.counted_qty.regex' => 'Enter a quantity of 0 or more, with up to 3 decimals',
        ];
    }
}
