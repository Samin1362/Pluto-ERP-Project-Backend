<?php

namespace App\Http\Requests\Platform;

use App\Domain\Shared\AuditableTypes;
use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /audit-logs query: the activity of one record (type + id); unknown parameters are a 422. */
class AuditLogListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['auditable_type', 'auditable_id', 'page'];
    }

    public function rules(): array
    {
        return [
            'auditable_type' => ['required', 'string', Rule::in(AuditableTypes::names())],
            'auditable_id' => ['required', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
