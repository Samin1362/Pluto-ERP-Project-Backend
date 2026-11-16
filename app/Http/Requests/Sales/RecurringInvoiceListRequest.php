<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /recurring-invoices query (PEP-517). */
class RecurringInvoiceListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'active', 'party_id'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'active' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'party_id' => ['sometimes', 'integer', Rule::exists('parties', 'id')->where('company_id', app(CurrentCompany::class)->id())],
        ];
    }
}
