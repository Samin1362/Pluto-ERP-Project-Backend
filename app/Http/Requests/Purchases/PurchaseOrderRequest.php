<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * PurchaseOrderInput (05 postPurchaseOrders / putPurchaseOrdersId; backlog P10.2-BE-01). Shape only, lines as
 * PurchaseLineInput (same formats as a bill; purchase_order_line_id has no meaning on an order and is ignored). The
 * party must be one of the company's suppliers. Active supplier, warehouse, products, tax rules and the exchange rate
 * of a foreign order are checked when the order is priced (PurchaseBillPreparer).
 */
class PurchaseOrderRequest extends StrictRequest
{
    private const QTY = '/^\d{1,15}(\.\d{1,3})?$/';

    private const COST = '/^\d{1,14}(\.\d{1,4})?$/';

    private const PCT = '/^\d{1,3}(\.\d{1,4})?$/';

    private const RATE = '/^\d{1,12}(\.\d{1,6})?$/';

    protected function allowedFields(): array
    {
        return ['party_id', 'order_date', 'expected_date', 'warehouse_id', 'payment_term_id', 'currency_code', 'exchange_rate', 'source_note', 'notes',
            'internal_note', 'lines'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['currency_code', 'source_note', 'notes', 'internal_note'] as $f) {
            if (is_string($this->input($f))) {
                $this->merge([$f => trim($this->input($f)) === '' ? null : trim($this->input($f))]);
            }
        }
        if (is_string($this->input('currency_code'))) {
            $this->merge(['currency_code' => strtoupper($this->input('currency_code'))]);
        }
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $c);

        return [
            'party_id' => ['required', 'integer', $exists('parties')->where('party_type', 'supplier')->whereNull('deleted_at')],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'expected_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'warehouse_id' => ['required', 'integer', $exists('warehouses')],
            'payment_term_id' => ['sometimes', 'nullable', 'integer', $exists('payment_terms')],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'exchange_rate' => ['sometimes', 'nullable', 'string', 'regex:'.self::RATE],
            'source_note' => ['sometimes', 'nullable', 'string', 'max:120'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:product_id,description,quantity,discount_pct,tax_rule_id,unit_cost,purchase_order_line_id'],
            'lines.*.product_id' => ['required', 'integer', $exists('products')->whereNull('deleted_at')],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'string', 'regex:'.self::QTY, 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit_cost' => ['required', 'string', 'regex:'.self::COST],
            'lines.*.discount_pct' => ['sometimes', 'nullable', 'string', 'regex:'.self::PCT],
            'lines.*.tax_rule_id' => ['sometimes', 'nullable', 'integer', $exists('tax_rules')],
            'lines.*.purchase_order_line_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.required' => 'Choose the supplier',
            'party_id.exists' => 'Choose one of your suppliers',
            'order_date.required' => 'Enter the order date',
            'expected_date.after_or_equal' => 'The expected date cannot be before the order date',
            'warehouse_id.required' => 'Choose the warehouse the goods are delivered to',
            'lines.required' => 'Add at least one line',
            'lines.min' => 'Add at least one line',
            'lines.*.product_id.required' => 'Choose an item',
            'lines.*.quantity.required' => 'Enter the quantity',
            'lines.*.quantity.regex' => 'Enter a quantity with up to 3 decimals',
            'lines.*.quantity.not_regex' => 'The quantity must be more than 0',
            'lines.*.unit_cost.required' => 'Enter the cost',
            'lines.*.unit_cost.regex' => 'Enter a cost of 0 or more with up to 4 decimals',
            'lines.*.discount_pct.regex' => 'Enter a discount between 0 and 100 %',
            'exchange_rate.regex' => 'Enter a rate with up to 6 decimals',
        ];
    }
}
