<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\StrictRequest;

/**
 * EmailRequest (05 postSalesInvoicesIdSend; backlog P5.1-BE-06). `to` must be present; an empty list means "the
 * customer's e-mail on file" (the service refuses when there is none). At most 10 addresses per list.
 */
class EmailRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['to', 'cc', 'message'];
    }

    public function rules(): array
    {
        return [
            'to' => ['present', 'array', 'max:10'],
            'to.*' => ['string', 'email:rfc', 'max:190', 'distinct'],
            'cc' => ['sometimes', 'array', 'max:10'],
            'cc.*' => ['string', 'email:rfc', 'max:190', 'distinct'],
            'message' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return ['to.present' => 'Add who the invoice goes to', 'to.*.email' => 'Enter a valid e-mail address', 'cc.*.email' => 'Enter a valid e-mail address'];
    }
}
