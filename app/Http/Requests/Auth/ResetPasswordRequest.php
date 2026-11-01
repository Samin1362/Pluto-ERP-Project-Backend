<?php

namespace App\Http\Requests\Auth;

use App\Domain\Access\AccountService;
use App\Http\Requests\StrictRequest;

/** POST /auth/password/reset (S2; NFR-011). */
class ResetPasswordRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['token', 'email', 'password', 'password_confirmation'];
    }

    public function rules(): array
    {
        return ['token' => ['required', 'string', 'max:200'], 'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'confirmed', AccountService::passwordRule()], 'password_confirmation' => ['required', 'string']];
    }

    public function messages(): array
    {
        return ['password.confirmed' => 'The two passwords do not match', 'password.min' => 'Use at least 10 characters',
            'password.uncompromised' => 'This password has appeared in a data breach; choose another'];
    }
}
