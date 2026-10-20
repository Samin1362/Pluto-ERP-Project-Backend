<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\StrictRequest;

/** LoginRequest schema: email, password, device_label. */
class LoginRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['email', 'password', 'device_label'];
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
            'device_label' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return ['email.required' => 'Enter your e-mail address', 'email.email' => 'Enter a valid e-mail address',
            'password.required' => 'Enter your password'];
    }
}
