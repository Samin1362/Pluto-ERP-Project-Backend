<?php

namespace App\Http\Requests\Sales;

use App\Domain\Sales\CreditNoteService;
use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * NoteInput for a credit note (05 postCreditNotes / putCreditNotesId; backlog P10.3-BE-02). Shape only: a customer, a
 * reason, an amount above 0 and the usage; refund details as RefundInput. Same-customer invoices (BR-021, R-20), active
 * money account and the invoice's balance (BR-023, on posting) are checked by CreditNoteService.
 */
class CreditNoteRequest extends StrictRequest
{
    private const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    protected function allowedFields(): array
    {
        return ['party_id', 'source_document_id', 'return_id', 'note_date', 'reason', 'amount', 'usage', 'apply_to_document_id', 'refund'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $c);

        return [
            'party_id' => ['required', 'integer', $exists('parties')->where('party_type', 'customer')->whereNull('deleted_at')],
            'source_document_id' => ['sometimes', 'nullable', 'integer', $exists('sales_invoices')],
            'return_id' => ['sometimes', 'nullable', 'integer', $exists('sales_returns')],
            'note_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::in(CreditNoteService::REASONS)],
            'amount' => ['required', 'string', 'regex:'.self::MONEY, 'not_regex:/^0+(\.0+)?$/'],
            'usage' => ['required', Rule::in(['apply', 'credit', 'refund'])],
            'apply_to_document_id' => ['sometimes', 'nullable', 'integer', $exists('sales_invoices')],
            'refund' => ['sometimes', 'nullable', 'array:money_account_id,method,reference'],
            'refund.money_account_id' => ['required_with:refund', 'integer', $exists('money_accounts')],
            'refund.method' => ['required_with:refund', Rule::in(['cash', 'cheque', 'bank_transfer', 'beftn', 'rtgs', 'npsb', 'bkash', 'nagad', 'card'])],
            'refund.reference' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.required' => 'Choose the customer',
            'party_id.exists' => 'Choose one of your customers',
            'note_date.required' => 'Enter the date',
            'reason.required' => 'Choose the reason',
            'reason.in' => 'Choose one of the listed reasons',
            'amount.required' => 'Enter the amount',
            'amount.regex' => 'Enter an amount with up to 2 decimals',
            'amount.not_regex' => 'The amount must be more than 0',
            'usage.required' => 'Choose how the credit is used',
            'refund.method.required_with' => 'Choose how the refund is paid',
        ];
    }
}
