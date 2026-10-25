<?php

namespace App\Http\Requests\Settlements;

use App\Http\Requests\StrictRequest;
use App\Queries\CustomerReceiptQuery;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /customer-receipts query (05 getCustomerReceipts, E14; backlog P6.1-BE-03): LISTQ + DATEQ + customer, account, unapplied. */
class CustomerReceiptListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'party_id', 'money_account_id', 'unapplied'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'party_id' => ['sometimes', 'integer', Rule::exists('parties', 'id')->where('company_id', $c)->where('party_type', 'customer')],
            'money_account_id' => ['sometimes', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', $c)],
            'unapplied' => ['sometimes', Rule::in(['true', 'false'])],
            'sort' => ['sometimes', Rule::in(CustomerReceiptQuery::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
