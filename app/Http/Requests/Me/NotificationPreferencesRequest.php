<?php

namespace App\Http\Requests\Me;

use App\Domain\Access\AccountService;
use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** PUT /me/notification-preferences (NotificationPreferences; K12). SMS is not offered in v1. */
class NotificationPreferencesRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['events', 'daily_summary_time'];
    }

    public function rules(): array
    {
        return [
            'events' => ['required', 'array'],
            'events.*.event_key' => ['required', 'distinct', Rule::in(array_keys(AccountService::EVENTS))],
            'events.*.in_app' => ['required', 'boolean'],
            'events.*.email' => ['required', 'boolean'],
            'events.*.sms' => ['sometimes', 'boolean', 'declined'],
            'daily_summary_time' => ['sometimes', 'nullable', Rule::in(AccountService::SUMMARY_TIMES)],
        ];
    }

    public function messages(): array
    {
        return ['events.*.sms.declined' => 'Text messages are not available yet', 'daily_summary_time.in' => 'Choose 07:30, 08:00 or 09:00'];
    }
}
