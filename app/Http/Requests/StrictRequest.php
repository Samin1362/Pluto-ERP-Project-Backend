<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base for API requests: every request schema has additionalProperties:false (05 §1), so an unknown or
 * misspelt field is a 422, never silently ignored. Authorization is done by route middleware (perm:...).
 */
abstract class StrictRequest extends FormRequest
{
    /** Top-level fields the contract allows; nested keys are checked by the rules themselves. */
    abstract protected function allowedFields(): array;

    public function authorize(): bool
    {
        return true;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $source = $this->isMethod('GET') ? $this->query() : $this->json()->all();
            foreach (array_diff(array_keys($source), $this->allowedFields()) as $unknown) {
                $v->errors()->add((string) $unknown, 'This field is not part of the API.');
            }
        });
    }

    /** Validated body of a JSON request (query string excluded). */
    public function body(): array
    {
        return $this->validated();
    }
}
