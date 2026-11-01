<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\StrictRequest;

/** POST /invitations/{token}/accept (AcceptInvitationRequest; S3): name and password for a new user, the password for an existing one. */
class AcceptInvitationRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['name', 'password', 'password_confirmation'];
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'password' => ['sometimes', 'nullable', 'string', 'max:200'],
            'password_confirmation' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }
}
