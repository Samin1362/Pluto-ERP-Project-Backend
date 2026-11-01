<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\StrictRequest;

/** POST /auth/password/forgot (S2). */
class ForgotPasswordRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['email'];
    }

    public function rules(): array
    {
        return ['email' => ['required', 'email', 'max:190']];
    }

    public function messages(): array
    {
        return ['email.required' => 'Enter your e-mail', 'email.email' => 'Enter a valid e-mail'];
    }
}
