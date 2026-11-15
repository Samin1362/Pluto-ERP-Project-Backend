<?php

namespace App\Http\Requests\PlatformConsole;

use App\Domain\Platform\Plans;
use App\Http\Requests\StrictRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/** POST /platform/plans, PUT /platform/plans/{id} (PlanInput; PEP-498). */
class PlanRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['slug', 'name', 'max_users', 'max_branches', 'modules', 'is_active'];
    }

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('plans', 'slug')->ignore($this->route('id'))],
            'name' => ['required', 'string', 'max:80'],
            'max_users' => ['required', 'integer', 'between:1,10000'],
            'max_branches' => ['present', 'nullable', 'integer', 'between:1,10000'],
            'modules' => ['present', 'array'],
            'modules.*' => ['string', 'distinct', Rule::in(Plans::MODULES)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $v) {
            $m = (array) $this->input('modules', []);
            if (in_array(Plans::LC_FINANCE, $m, true) && ! in_array(Plans::LC_MANAGEMENT, $m, true)) {
                $v->errors()->add('modules', 'LC finance needs LC management in the same plan');
            }
        });
    }

    public function messages(): array
    {
        return ['slug.regex' => 'Use lower-case letters, digits and _ only, e.g. import_pro', 'slug.unique' => 'Another plan already uses this short name',
            'max_branches.present' => 'Give a branch limit, or leave it empty for unlimited'];
    }
}
