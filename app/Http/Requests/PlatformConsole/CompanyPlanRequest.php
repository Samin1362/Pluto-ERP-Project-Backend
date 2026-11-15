<?php

namespace App\Http\Requests\PlatformConsole;

use App\Domain\Platform\Plans;
use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** POST /platform/companies/{id}/plan (CompanyPlanInput; PEP-498). */
class CompanyPlanRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['plan_id', 'overrides'];
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'overrides' => ['sometimes', 'nullable', 'array:max_users,max_branches,modules_on,modules_off'],
            'overrides.max_users' => ['sometimes', 'nullable', 'integer', 'between:1,10000'],
            'overrides.max_branches' => ['sometimes', 'nullable', 'integer', 'between:1,10000'],
            'overrides.modules_on' => ['sometimes', 'array'],
            'overrides.modules_on.*' => ['string', 'distinct', Rule::in(Plans::MODULES)],
            'overrides.modules_off' => ['sometimes', 'array'],
            'overrides.modules_off.*' => ['string', 'distinct', Rule::in(Plans::MODULES)],
        ];
    }
}
