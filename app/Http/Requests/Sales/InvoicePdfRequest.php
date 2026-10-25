<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /sales-invoices/{id}/pdf query (05 getSalesInvoicesIdPdf; GAP-12; backlog P5.1-BE-05): the invoice or its delivery challan. */
class InvoicePdfRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['variant'];
    }

    public function rules(): array
    {
        return ['variant' => ['sometimes', Rule::in(['invoice', 'challan'])]];
    }
}
