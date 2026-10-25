<?php

namespace App\Http\Requests\Parties;

use App\Http\Requests\StrictRequest;
use App\Queries\PartyAccountQuery;
use Illuminate\Validation\Rule;

/**
 * Query parameters of the party account endpoints (05 getPartiesIdLedger, …Transactions, getPartiesOutstanding,
 * getPartiesIdStatement(+Pdf); backlog P6.1-BE-05). The route name picks the endpoint's parameters; unknown ones are 422.
 */
class PartyAccountRequest extends StrictRequest
{
    private const FIELDS = [
        'parties.ledger' => ['date_from', 'date_to', 'density', 'page'],
        'parties.transactions' => ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'document_type'],
        'parties.outstanding' => ['page', 'per_page', 'sort', 'q', 'type', 'bucket', 'as_of'],
        'parties.statement' => ['date_from', 'date_to', 'aging'],
        'parties.statement.pdf' => ['date_from', 'date_to', 'aging'],
    ];

    protected function allowedFields(): array
    {
        return self::FIELDS[$this->route()?->getName()] ?? [];
    }

    public function rules(): array
    {
        return [
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'as_of' => ['sometimes', 'date_format:Y-m-d'],
            'density' => ['sometimes', Rule::in(['detailed', 'condensed'])],
            'aging' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'document_type' => ['sometimes', Rule::in(['sales_invoice', 'purchase_bill', 'receipt', 'payment'])],
            'type' => ['sometimes', Rule::in(['customer', 'supplier'])],
            'bucket' => ['sometimes', Rule::in([...PartyAccountQuery::BUCKETS, 'total'])],
            'sort' => ['sometimes', Rule::in($this->route()?->getName() === 'parties.outstanding'
                ? ['total', '-total', 'name', '-name', 'code', '-code', ...PartyAccountQuery::BUCKETS, ...array_map(fn ($b) => "-{$b}", PartyAccountQuery::BUCKETS)]
                : ['date', '-date', 'number', '-number', 'amount', '-amount'])],
            'q' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
