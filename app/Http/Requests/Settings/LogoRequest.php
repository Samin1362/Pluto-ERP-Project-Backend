<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;

/** PUT /company/logo (FileUpload; K2): PNG or SVG; the content is sniffed by CompanyService. */
class LogoRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['file'];
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:1024'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose a PNG or SVG file',
            'file.max' => 'The logo can be up to 1 MB',
        ];
    }
}
