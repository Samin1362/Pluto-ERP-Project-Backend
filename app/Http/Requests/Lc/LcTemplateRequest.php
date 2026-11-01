<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;

/** POST /lc-document-templates, PUT /lc-document-templates/{id} (LcDocumentTemplateInput). */
class LcTemplateRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['name', 'is_default', 'items'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'is_default' => ['sometimes', 'boolean'],
            'items' => ['present', 'array'],
            'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.provided_by' => ['required', 'string', 'max:80'],
            'items.*.copies' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'items.*.note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'items.*.required_by_default' => ['sometimes', 'boolean'],
        ];
    }
}
