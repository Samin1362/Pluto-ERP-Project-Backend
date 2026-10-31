<?php

namespace App\Http\Requests\Attachments;

use App\Domain\Attachments\AttachmentService;
use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/**
 * AttachmentUpload (05 postAttachments; backlog P13.1-BE-01): multipart file, the record type and id. The id may be
 * left out for a voucher still being written (a pending file, linked by POST /ie-entries). Size and type are checked by
 * AttachmentService from the content (413 / 415), not here.
 */
class AttachmentRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['file', 'attachable_type', 'attachable_id'];
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file'],
            'attachable_type' => ['required', 'string', Rule::in(AttachmentService::TYPES)],
            'attachable_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return ['file.required' => 'Choose a JPG, PNG or PDF', 'file.file' => 'The upload did not arrive; files may be up to 10 MB',
            'attachable_type.in' => 'Files attach to an invoice, bill, voucher, LC, LC document, stock adjustment or party'];
    }
}
