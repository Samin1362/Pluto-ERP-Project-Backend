<?php

namespace App\Http\Requests\Sales;

use App\Domain\Sales\SalesReturnService;
use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * ReturnInput for a sales return (05 postSalesReturns / putSalesReturnsId; backlog P10.3-BE-01). Shape only: the
 * invoice must be the company's, stock_action restock or write_off, each line an invoice line id with a quantity and a
 * reason. Posted invoice, lines of that invoice and returnable quantities (BR-022) are checked by SalesReturnService.
 */
class SalesReturnRequest extends StrictRequest
{
    private const QTY = '/^\d{1,15}(\.\d{1,3})?$/';

    protected function allowedFields(): array
    {
        return ['source_document_id', 'return_date', 'warehouse_id', 'stock_action', 'usage', 'apply_to_document_id', 'notes', 'lines'];
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
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $c);

        return [
            'source_document_id' => ['required', 'integer', $exists('sales_invoices')],
            'return_date' => ['required', 'date_format:Y-m-d'],
            'warehouse_id' => ['required', 'integer', $exists('warehouses')],
            'stock_action' => ['required', Rule::in(['restock', 'write_off'])],
            'usage' => ['sometimes', 'nullable', Rule::in(['apply', 'credit', 'refund'])],
            'apply_to_document_id' => ['sometimes', 'nullable', 'integer', $exists('sales_invoices')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:source_line_id,quantity,reason'],
            'lines.*.source_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'regex:'.self::QTY, 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.reason' => ['required', Rule::in(SalesReturnService::REASONS)],
        ];
    }

    public function messages(): array
    {
        return [
            'source_document_id.required' => 'Choose the invoice the goods came from',
            'source_document_id.exists' => 'Choose one of your invoices',
            'return_date.required' => 'Enter the return date',
            'warehouse_id.required' => 'Choose the warehouse the goods come back to',
            'stock_action.in' => 'Choose restock or write off',
            'lines.required' => 'Add at least one returned item',
            'lines.min' => 'Add at least one returned item',
            'lines.*.source_line_id.distinct' => 'Each invoice line appears once',
            'lines.*.quantity.required' => 'Enter the quantity returned',
            'lines.*.quantity.regex' => 'Enter a quantity with up to 3 decimals',
            'lines.*.quantity.not_regex' => 'The quantity must be more than 0',
            'lines.*.reason.required' => 'Choose why it came back',
            'lines.*.reason.in' => 'Choose one of the listed reasons',
        ];
    }
}
