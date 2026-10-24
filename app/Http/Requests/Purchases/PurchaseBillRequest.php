<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * PurchaseBillInput (05 postPurchaseBillsCalculate / postPurchaseBills / putPurchaseBillsId; backlog P4.2-BE-01/02).
 * Shape only: amounts are decimal strings (cost 4 decimals, quantities 3, percents 4, rates 6), references must be the
 * company's. Business rules (active supplier and products, tax rules for purchases, currency and rate, unique supplier
 * bill number, open period) are checked by the purchase bill services.
 */
class PurchaseBillRequest extends StrictRequest
{
    private const QTY = '/^\d{1,15}(\.\d{1,3})?$/';

    private const COST = '/^\d{1,14}(\.\d{1,4})?$/';

    private const PCT = '/^\d{1,3}(\.\d{1,4})?$/';

    private const RATE = '/^\d{1,12}(\.\d{1,6})?$/';

    protected function allowedFields(): array
    {
        return ['party_id', 'supplier_bill_no', 'bill_date', 'due_date', 'payment_term_id', 'warehouse_id', 'purchase_order_id', 'letter_of_credit_id',
            'lc_shipment_id', 'currency_code', 'exchange_rate', 'branch_id', 'notes', 'internal_note', 'receipt_condition_note', 'lines'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['supplier_bill_no', 'currency_code', 'notes', 'internal_note', 'receipt_condition_note'] as $f) {
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
            'supplier_bill_no' => ['required', 'string', 'max:60'],
            'bill_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:bill_date'],
            'payment_term_id' => ['sometimes', 'nullable', 'integer', $exists('payment_terms')],
            'warehouse_id' => ['required', 'integer', $exists('warehouses')],
            'purchase_order_id' => ['sometimes', 'nullable', 'integer', $exists('purchase_orders')],
            'letter_of_credit_id' => ['sometimes', 'nullable', 'integer', $exists('letters_of_credit')],
            'lc_shipment_id' => ['sometimes', 'nullable', 'integer', $exists('lc_shipments')],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3', Rule::exists('currencies', 'code')],
            'exchange_rate' => ['sometimes', 'nullable', 'string', 'regex:'.self::RATE],
            'branch_id' => ['sometimes', 'nullable', 'integer', $exists('branches')->where('is_active', true)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'receipt_condition_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:product_id,description,quantity,discount_pct,tax_rule_id,unit_cost,purchase_order_line_id'],
            'lines.*.product_id' => ['required', 'integer', $exists('products')->whereNull('deleted_at')],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'string', 'regex:'.self::QTY, 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit_cost' => ['required', 'string', 'regex:'.self::COST],
            'lines.*.discount_pct' => ['sometimes', 'nullable', 'string', 'regex:'.self::PCT],
            'lines.*.tax_rule_id' => ['sometimes', 'nullable', 'integer', $exists('tax_rules')],
            'lines.*.purchase_order_line_id' => ['sometimes', 'nullable', 'integer', Rule::exists('purchase_order_lines', 'id')],
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.required' => 'Choose the supplier',
            'party_id.exists' => 'Choose one of your suppliers',
            'supplier_bill_no.required' => "Enter the supplier's bill number",
            'bill_date.required' => 'Enter the bill date',
            'due_date.after_or_equal' => 'The due date cannot be before the bill date',
            'warehouse_id.required' => 'Choose the warehouse the goods go into',
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
