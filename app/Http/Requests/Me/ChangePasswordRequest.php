<?php

namespace App\Http\Requests\Me;

use App\Domain\Access\AccountService;
use App\Http\Requests\StrictRequest;

/** PUT /me/password (ChangePasswordRequest; NFR-011). */
class ChangePasswordRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['current_password', 'password', 'password_confirmation'];
    }

    public function rules(): array
    {
        return ['current_password' => ['required', 'string', 'max:200'], 'password' => ['required', 'string', 'confirmed', AccountService::passwordRule()],
            'password_confirmation' => ['required', 'string']];
    }

    public function messages(): array
    {
        return ['current_password.required' => 'Enter your current password', 'password.confirmed' => 'The two new passwords do not match',
            'password.min' => 'Use at least 10 characters', 'password.uncompromised' => 'This password has appeared in a data breach; choose another'];
    }
}
