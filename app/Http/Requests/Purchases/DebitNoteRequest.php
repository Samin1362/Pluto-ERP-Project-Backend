<?php

namespace App\Http\Requests\Purchases;

use App\Domain\Purchases\DebitNoteService;
use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * NoteInput for a debit note (05 postDebitNotes / putDebitNotesId; backlog P10.4-BE-02). Shape only: a supplier, a
 * reason, an amount above 0 and the usage. Same-supplier bills (BR-021, R-20) and the bill's balance (BR-023, on
 * posting) are checked by DebitNoteService. A refund needs no details now: the money is recorded when it arrives.
 */
class DebitNoteRequest extends StrictRequest
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
            'party_id' => ['required', 'integer', $exists('parties')->where('party_type', 'supplier')->whereNull('deleted_at')],
            'source_document_id' => ['sometimes', 'nullable', 'integer', $exists('purchase_bills')],
            'return_id' => ['sometimes', 'nullable', 'integer', $exists('purchase_returns')],
            'note_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::in(DebitNoteService::REASONS)],
            'amount' => ['required', 'string', 'regex:'.self::MONEY, 'not_regex:/^0+(\.0+)?$/'],
            'usage' => ['required', Rule::in(['apply', 'credit', 'refund'])],
            'apply_to_document_id' => ['sometimes', 'nullable', 'integer', $exists('purchase_bills')],
            'refund' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'party_id.required' => 'Choose the supplier',
            'party_id.exists' => 'Choose one of your suppliers',
            'note_date.required' => 'Enter the date',
            'reason.required' => 'Choose the reason',
            'reason.in' => 'Choose one of the listed reasons',
            'amount.required' => 'Enter the amount',
            'amount.regex' => 'Enter an amount with up to 2 decimals',
            'amount.not_regex' => 'The amount must be more than 0',
            'usage.required' => 'Choose how the debit is used',
        ];
    }
}
