<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * Queries of the cash and bank reads (05 getBankingOverview, getMoneyTransactions, getMoneyAccountsIdBook; G1, G11–G13;
 * backlog P12.1-BE-01). Overview: none. Transactions: LISTQ + DATEQ + direction, account. Book: DATEQ, view (all, in,
 * out — which lines are listed, never the balances), page.
 */
class BankingQueryRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return match (true) {
            $this->is('*/money-transactions') => ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'direction', 'money_account_id'],
            $this->is('*/book') => ['date_from', 'date_to', 'view', 'page'],
            default => [],
        };
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'direction' => ['sometimes', Rule::in(['in', 'out', 'transfer'])],
            'money_account_id' => ['sometimes', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', $c)],
            'view' => ['sometimes', Rule::in(['all', 'in', 'out'])],
            'sort' => ['sometimes', Rule::in(['date', '-date'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
