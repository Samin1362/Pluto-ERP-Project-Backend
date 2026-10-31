<?php

namespace App\Http\Requests\Purchases;

use App\Domain\Purchases\PurchaseReturnService;
use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * ReturnInput for a purchase return (05 postPurchaseReturns / putPurchaseReturnsId; backlog P10.4-BE-01). Shape only:
 * the bill must be the company's, stock_action send_back or already_disposed, each line a bill line id with a quantity
 * and a reason. Posted bill, lines of that bill and returnable quantities (BR-022) are checked by PurchaseReturnService.
 */
class PurchaseReturnRequest extends StrictRequest
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
            'source_document_id' => ['required', 'integer', $exists('purchase_bills')],
            'return_date' => ['required', 'date_format:Y-m-d'],
            'warehouse_id' => ['required', 'integer', $exists('warehouses')],
            'stock_action' => ['required', Rule::in(['send_back', 'already_disposed'])],
            'usage' => ['sometimes', 'nullable', Rule::in(['apply', 'credit', 'refund'])],
            'apply_to_document_id' => ['sometimes', 'nullable', 'integer', $exists('purchase_bills')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:source_line_id,quantity,reason'],
            'lines.*.source_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'regex:'.self::QTY, 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.reason' => ['required', Rule::in(PurchaseReturnService::REASONS)],
        ];
    }

    public function messages(): array
    {
        return [
            'source_document_id.required' => 'Choose the bill the goods came on',
            'source_document_id.exists' => 'Choose one of your bills',
            'return_date.required' => 'Enter the return date',
            'warehouse_id.required' => 'Choose the warehouse the goods leave from',
            'stock_action.in' => 'Choose send back or already disposed',
            'lines.required' => 'Add at least one returned item',
            'lines.min' => 'Add at least one returned item',
            'lines.*.source_line_id.distinct' => 'Each bill line appears once',
            'lines.*.quantity.required' => 'Enter the quantity returned',
            'lines.*.quantity.regex' => 'Enter a quantity with up to 3 decimals',
            'lines.*.quantity.not_regex' => 'The quantity must be more than 0',
            'lines.*.reason.required' => 'Choose why it goes back',
            'lines.*.reason.in' => 'Choose one of the listed reasons',
        ];
    }
}
