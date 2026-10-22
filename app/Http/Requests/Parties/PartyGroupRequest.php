<?php

namespace App\Http\Requests\Parties;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** PartyGroupInput (05 §10, backlog P2.1-BE-02): a name unique per company and party type ("Add group…" in B2/C2). */
class PartyGroupRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['party_type', 'name'];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name')) === '' ? null : trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return [
            'party_type' => ['required', Rule::in(['customer', 'supplier'])],
            'name' => ['required', 'string', 'max:80', Rule::unique('party_groups', 'name')
                ->where('company_id', app(CurrentCompany::class)->id())->where('party_type', $this->input('party_type'))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter a group name',
            'name.max' => 'Group names have at most 80 characters',
            'name.unique' => 'A '.($this->input('party_type') === 'supplier' ? 'supplier' : 'customer').' group with this name already exists',
        ];
    }
}
