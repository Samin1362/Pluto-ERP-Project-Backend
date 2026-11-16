<?php

namespace App\Http\Requests\Parties;

use App\Http\Requests\StrictRequest;

/** GET /parties/{id}/payment-reminders query (PEP-516). */
class PaymentReminderListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
