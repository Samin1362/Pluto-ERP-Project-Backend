<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * SalesInvoiceInput (05 postSalesInvoicesCalculate / postSalesInvoices / putSalesInvoicesId; backlog P5.1-BE-01/02).
 * Shape only: amounts are decimal strings (price 2 decimals, quantities 3, percents 4), references must be the
 * company's, the customer must be a customer. Business rules (active customer and products, tax rules for sales, stock,
 * credit limit, open period) are checked by the sales invoice services.
 */
class SalesInvoiceRequest extends StrictRequest
{
    private const QTY = '/^\d{1,15}(\.\d{1,3})?$/';

    private const PRICE = '/^\d{1,16}(\.\d{1,2})?$/';

    private const PCT = '/^\d{1,3}(\.\d{1,4})?$/';

    protected function allowedFields(): array
    {
        return ['party_id', 'invoice_date', 'due_date', 'payment_term_id', 'warehouse_id', 'customer_po_ref', 'quotation_id', 'branch_id', 'notes',
            'internal_note', 'lines'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['customer_po_ref', 'notes', 'internal_note'] as $f) {
            if (is_string($this->input($f))) {
                $this->merge([$f => trim($this->input($f)) === '' ? null : trim($this->input($f))]);
            }
        }
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $c);

        return [
            'party_id' => ['required', 'integer', $exists('parties')->where('party_type', 'customer')->whereNull('deleted_at')],
            'invoice_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'payment_term_id' => ['sometimes', 'nullable', 'integer', $exists('payment_terms')],
            'warehouse_id' => ['required', 'integer', $exists('warehouses')],
            'customer_po_ref' => ['sometimes', 'nullable', 'string', 'max:60'],
            'quotation_id' => ['sometimes', 'nullable', 'integer', $exists('quotations')],
            'branch_id' => ['sometimes', 'nullable', 'integer', $exists('branches')->where('is_active', true)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'internal_note' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:product_id,description,quantity,unit_price,discount_pct,tax_rule_id'],
            'lines.*.product_id' => ['required', 'integer', $exists('products')->whereNull('deleted_at')],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'string', 'regex:'.self::QTY, 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit_price' => ['sometimes', 'nullable', 'string', 'regex:'.self::PRICE],
            'lines.*.discount_pct' => ['sometimes', 'nullable', 'string', 'regex:'.self::PCT, 'numeric', 'max:100'],
            'lines.*.tax_rule_id' => ['sometimes', 'nullable', 'integer', $exists('tax_rules')],
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.required' => 'Choose the customer',
            'party_id.exists' => 'Choose one of your customers',
            'invoice_date.required' => 'Enter the invoice date',
            'due_date.after_or_equal' => 'The due date cannot be before the invoice date',
            'warehouse_id.required' => 'Choose the warehouse the goods leave from',
            'lines.required' => 'Add at least one line',
            'lines.min' => 'Add at least one line',
            'lines.*.product_id.required' => 'Choose an item',
            'lines.*.quantity.required' => 'Enter the quantity',
            'lines.*.quantity.regex' => 'Enter a quantity with up to 3 decimals',
            'lines.*.quantity.not_regex' => 'The quantity must be more than 0',
            'lines.*.unit_price.regex' => 'Enter a price of 0 or more with up to 2 decimals',
            'lines.*.discount_pct.regex' => 'Enter a discount between 0 and 100 %',
            'lines.*.discount_pct.max' => 'Enter a discount between 0 and 100 %',
        ];
    }
}
