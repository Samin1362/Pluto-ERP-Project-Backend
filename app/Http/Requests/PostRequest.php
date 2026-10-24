<?php

namespace App\Http\Requests;

/**
 * PostRequest (05): confirm_backdated for dates older than the back-date window (BR-053), acknowledge_warnings for
 * warnings the user has seen. `receipt` (cash received with a sales invoice, R-28) arrives with sales invoices (P5).
 */
class PostRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['confirm_backdated', 'acknowledge_warnings'];
    }

    public function rules(): array
    {
        return [
            'confirm_backdated' => ['sometimes', 'boolean'],
            'acknowledge_warnings' => ['sometimes', 'array', 'max:20'],
            'acknowledge_warnings.*' => ['string', 'max:40'],
        ];
    }
}
