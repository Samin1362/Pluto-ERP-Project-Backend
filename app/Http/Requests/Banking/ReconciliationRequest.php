<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * Bodies of the reconciliation actions (05 Reconciliation*; G10; backlog P12.1-BE-05), by route: start
 * (ReconciliationStart), match (MatchRequest), create-entry (CreateEntryFromLine), pair (PairRequest), and the
 * `filter` query of GET.
 */
class ReconciliationRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return match (true) {
            $this->isMethod('GET') => ['filter'],
            $this->is('*/match') => ['journal_line_id'],
            $this->is('*/create-entry') => ['kind', 'ledger_id', 'party_id', 'narration'],
            $this->is('*/pair') => ['line_ids'],
            default => ['money_account_id', 'statement_date', 'statement_balance'],
        };
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();
        if ($this->isMethod('GET')) {
            return ['filter' => ['sometimes', Rule::in(['all', 'unmatched', 'handled'])]];
        }

        return match (true) {
            $this->is('*/match') => ['journal_line_id' => ['required', 'integer', Rule::exists('journal_lines', 'id')->where('company_id', $c)]],
            $this->is('*/create-entry') => [
                'kind' => ['required', Rule::in(['receipt', 'bank_charge', 'payment'])],
                'ledger_id' => ['required', 'integer', Rule::exists('ledgers', 'id')->where('company_id', $c)],
                'party_id' => ['sometimes', 'nullable', 'integer', Rule::exists('parties', 'id')->where('company_id', $c)],
                'narration' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            $this->is('*/pair') => ['line_ids' => ['required', 'array', 'min:1', 'max:20'], 'line_ids.*' => ['integer', 'distinct']],
            default => [
                'money_account_id' => ['required', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', $c)],
                'statement_date' => ['required', 'date_format:Y-m-d'],
                'statement_balance' => ['required', 'string', 'regex:/^-?\d{1,16}(\.\d{1,2})?$/'],
            ],
        };
    }

    public function messages(): array
    {
        return ['statement_balance.regex' => 'Enter the closing balance on the statement', 'kind.required' => 'Choose what the entry is'];
    }
}
