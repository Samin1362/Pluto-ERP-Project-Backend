<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\StatusChangeRequest;

/** POST /quotations/{id}/status (backlog P10.1-BE-01): accepted, declined or expired. */
class QuotationStatusRequest extends StatusChangeRequest
{
    protected function statuses(): array
    {
        return ['accepted', 'declined', 'expired'];
    }
}
