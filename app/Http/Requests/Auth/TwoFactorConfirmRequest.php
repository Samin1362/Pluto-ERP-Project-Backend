<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\StrictRequest;

/** TwoFactorConfirm schema. */
class TwoFactorConfirmRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['code'];
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'regex:/^\d{6}$/']];
    }

    public function messages(): array
    {
        return ['code.required' => 'Enter the 6-digit code from your authenticator app', 'code.regex' => 'The code has 6 digits'];
    }
}
