<?php

namespace App\Http\Requests\Attachments;

use App\Domain\Attachments\AttachmentService;
use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /attachments (backlog P13.2-FE-07 tabs): the files of one record. */
class AttachmentListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['attachable_type', 'attachable_id'];
    }

    public function rules(): array
    {
        return [
            'attachable_type' => ['required', 'string', Rule::in(AttachmentService::TYPES)],
            'attachable_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
