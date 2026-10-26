<?php

namespace App\Http\Requests\Approvals;

use App\Http\Requests\StrictRequest;

/** ApprovalDecision (05): an optional comment to approve; reject requires it (checked by the service: comment_required). */
class ApprovalDecisionRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['comment'];
    }

    public function rules(): array
    {
        return ['comment' => ['sometimes', 'nullable', 'string', 'max:500']];
    }
}
