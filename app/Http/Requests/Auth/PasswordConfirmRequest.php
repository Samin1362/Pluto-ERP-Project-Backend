<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\StrictRequest;

/** PasswordConfirm schema: re-enter the password for a sensitive change. */
class PasswordConfirmRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['password'];
    }

    public function rules(): array
    {
        return ['password' => ['required', 'string', 'max:200']];
    }

    public function messages(): array
    {
        return ['password.required' => 'Enter your password'];
    }
}
