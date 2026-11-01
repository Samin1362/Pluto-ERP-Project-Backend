<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;

/** POST /backups/{id}/restore (RestoreRequest; K15; BR-062): the confirmation text RESTORE. */
class RestoreRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['confirmation'];
    }

    public function rules(): array
    {
        return [
            'confirmation' => ['required', 'in:RESTORE'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.required' => 'Type RESTORE to confirm',
            'confirmation.in' => 'Type RESTORE to confirm',
        ];
    }
}
