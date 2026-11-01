<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;

/** PATCH /number-sequences/{id} (NumberSequenceUpdate; K3; BR-006). */
class NumberSequenceRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['prefix', 'next_number', 'padding'];
    }

    public function rules(): array
    {
        return [
            'prefix' => ['sometimes', 'string', 'max:20', 'regex:/^[A-Za-z0-9\/-]*$/'],
            'next_number' => ['sometimes', 'integer', 'min:1', 'max:99999999'],
            'padding' => ['sometimes', 'integer', 'between:1,8'],
        ];
    }

    public function messages(): array
    {
        return [
            'prefix.regex' => 'Use letters, digits, dashes and slashes',
            'padding.between' => 'Pad to 1 to 8 digits',
        ];
    }
}
