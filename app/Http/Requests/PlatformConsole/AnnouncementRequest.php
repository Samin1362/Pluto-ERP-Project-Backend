<?php

namespace App\Http\Requests\PlatformConsole;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** POST /platform/announcements, PUT /platform/announcements/{id} (AnnouncementInput; PEP-513). */
class AnnouncementRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['title', 'body', 'tone', 'starts_at', 'ends_at', 'is_active'];
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'body' => ['sometimes', 'nullable', 'string', 'max:500'],
            'tone' => ['required', Rule::in(['info', 'warning', 'success'])],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['title.required' => 'Write a short title', 'ends_at.after' => 'The end must be after the start'];
    }
}
