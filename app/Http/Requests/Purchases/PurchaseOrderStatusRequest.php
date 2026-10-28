<?php

namespace App\Http\Requests\Purchases;

use App\Http\Requests\StatusChangeRequest;

/** POST /purchase-orders/{id}/status (backlog P10.2-BE-01): closed or cancelled. */
class PurchaseOrderStatusRequest extends StatusChangeRequest
{
    protected function statuses(): array
    {
        return ['closed', 'cancelled'];
    }
}
