<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /roles, PUT /roles/{id} (RoleInput; K11; R-10: both limits are required, 0.00 = everything needs approval). */
class RoleRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['name', 'description', 'permissions', 'payment_approval_limit', 'journal_approval_limit', 'copy_from_role_id'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'key')],
            'payment_approval_limit' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'journal_approval_limit' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999999'],
            'copy_from_role_id' => ['sometimes', 'nullable', 'integer', Rule::exists('roles', 'id')->where('company_id', app(CurrentCompany::class)->id())],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter a role name',
            'payment_approval_limit.required' => 'Enter the payment approval limit (0.00 = every payment needs approval)',
            'journal_approval_limit.required' => 'Enter the journal approval limit (0.00 = every journal needs approval)',
            'permissions.*.exists' => 'Unknown permission',
        ];
    }
}
