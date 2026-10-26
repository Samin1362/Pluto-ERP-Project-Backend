<?php

namespace App\Http\Requests\Approvals;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /approvals query (05 getApprovals): status (default pending) and page. */
class ApprovalListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['status', 'page'];
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
