<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /invitations (InvitationCreate; K9; BR-059, R-18). */
class InvitationRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['email', 'name', 'role_id', 'branch_ids'];
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'branch_ids' => ['sometimes', 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')->where('company_id', app(CurrentCompany::class)->id())],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => 'Enter their e-mail',
            'email.email' => 'Enter a valid e-mail',
            'role_id.required' => 'Choose a role',
            'role_id.exists' => 'Choose a role of this company',
        ];
    }
}
