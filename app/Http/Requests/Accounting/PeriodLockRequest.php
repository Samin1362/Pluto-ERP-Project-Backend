<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\StrictRequest;

/** PeriodLockRequest (05 postAccountingPeriodsIdLock): an optional note shown on the locked period. */
class PeriodLockRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['note'];
    }

    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'max:255']];
    }
}
