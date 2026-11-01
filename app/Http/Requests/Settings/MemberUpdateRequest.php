<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** PATCH /users/{id} (MemberUpdate; K10; BR-060, BR-038). */
class MemberUpdateRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['role_id', 'branch_ids'];
    }

    public function rules(): array
    {
        return [
            'role_id' => ['sometimes', 'integer', Rule::exists('roles', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')->where('company_id', app(CurrentCompany::class)->id())],
        ];
    }

    public function messages(): array
    {
        return [
            'role_id.exists' => 'Choose a role of this company',
        ];
    }
}
