<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /users (K8). */
class MemberListRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['q', 'status', 'role_id', 'page', 'per_page', 'sort'];
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(['active', 'invited', 'deactivated'])],
            'role_id' => ['sometimes', 'integer'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', Rule::in(['name', '-name', 'last_active', '-last_active'])],
        ];
    }
}
