<?php

namespace App\Http\Requests\Settlements;

use App\Http\Requests\StrictRequest;
use App\Queries\CustomerReceiptQuery;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /supplier-payments query (05 getSupplierPayments, F12; backlog P8.1-BE-01): LISTQ + DATEQ + supplier, account, status. */
class SupplierPaymentListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'party_id', 'money_account_id', 'status'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'party_id' => ['sometimes', 'integer', Rule::exists('parties', 'id')->where('company_id', $c)->where('party_type', 'supplier')],
            'money_account_id' => ['sometimes', 'integer', Rule::exists('money_accounts', 'id')->where('company_id', $c)],
            'status' => ['sometimes', Rule::in(['draft', 'submitted', 'approved', 'rejected', 'posted', 'reversed'])],
            'sort' => ['sometimes', Rule::in(CustomerReceiptQuery::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
