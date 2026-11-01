<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /branches, PATCH /branches/{id} (BranchInput; K1). */
class BranchRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['code', 'name', 'kind', 'address', 'phone', 'is_head_office', 'is_active'];
    }

    public function rules(): array
    {
        return [
            'code' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:20', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('branches', 'code')->where('company_id', app(CurrentCompany::class)->id())->ignore($this->route('id'))],
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:120'],
            'kind' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'is_head_office' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another location uses this code',
            'code.regex' => 'Use letters, digits and dashes',
            'name.required' => 'Enter a name',
        ];
    }
}
