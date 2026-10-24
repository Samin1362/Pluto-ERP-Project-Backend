<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** UnitInput (05 postUnits, backlog P3.1-BE-01): a name unique per company and how many decimals its quantities allow. */
class UnitRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['name', 'decimals'];
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
            'name' => ['required', 'string', 'max:30', Rule::unique('units', 'name')->where('company_id', app(CurrentCompany::class)->id())],
            // Quantities are stored with 3 decimals (decimal(18,3)), so a unit cannot allow more.
            'decimals' => ['sometimes', 'integer', 'min:0', 'max:3'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter a unit name',
            'name.max' => 'Unit names have at most 30 characters',
            'name.unique' => 'This unit already exists',
            'decimals.max' => 'Quantities allow at most 3 decimals',
        ];
    }
}
