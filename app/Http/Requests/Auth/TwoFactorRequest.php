<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\StrictRequest;

/** TwoFactorRequest schema: challenge_token plus exactly one of code (6 digits) or recovery_code. */
class TwoFactorRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['challenge_token', 'code', 'recovery_code'];
    }

    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'required_without:recovery_code', 'prohibits:recovery_code', 'string', 'regex:/^\d{6}$/'],
            'recovery_code' => ['nullable', 'required_without:code', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return ['code.required_without' => 'Enter the 6-digit code from your authenticator app',
            'code.regex' => 'The code has 6 digits', 'recovery_code.required_without' => 'Enter a recovery code',
            'code.prohibits' => 'Send either a code or a recovery code, not both'];
    }
}
