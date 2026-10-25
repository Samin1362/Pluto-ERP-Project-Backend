<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** GET /sales-invoices query (05 getSalesInvoices, E2; backlog P5.1-BE-02): LISTQ + DATEQ + status, payment, overdue, customer. */
class SalesInvoiceListRequest extends StrictRequest
{
    public const SORTS = ['invoice_date', '-invoice_date', 'number', '-number', 'due_date', '-due_date', 'total', '-total', 'balance_due', '-balance_due'];

    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'date_from', 'date_to', 'status', 'payment_status', 'overdue', 'party_id'];
    }

    public function rules(): array
    {
        $c = app(CurrentCompany::class)->id();

        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['sometimes', Rule::in(['draft', 'posted', 'cancelled'])],
            'payment_status' => ['sometimes', Rule::in(['unpaid', 'part_paid', 'paid'])],
            'overdue' => ['sometimes', Rule::in(['true', 'false'])],
            'party_id' => ['sometimes', 'integer', Rule::exists('parties', 'id')->where('company_id', $c)->where('party_type', 'customer')],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
