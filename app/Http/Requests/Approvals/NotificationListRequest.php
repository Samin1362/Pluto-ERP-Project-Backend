<?php

namespace App\Http\Requests\Approvals;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /notifications query (05 getNotifications): page, per_page, category, unread. */
class NotificationListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'category', 'unread'];
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'category' => ['sometimes', Rule::in(['approvals', 'alerts', 'info'])],
            'unread' => ['sometimes', Rule::in(['true', 'false'])],
        ];
    }
}
