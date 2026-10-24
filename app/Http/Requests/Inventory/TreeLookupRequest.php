<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * LookupInput (05) for the two inventory trees: product categories (D5, P3.1-BE-02) and stock groups (D6, P3.1-BE-03).
 * POST needs a name; PATCH is partial. Tree rules (depth, cycles) are checked by CatalogueService.
 */
abstract class TreeLookupRequest extends StrictRequest
{
    abstract protected function table(): string;

    /** Fields of LookupInput that this tree does not have (rejected like unknown fields). */
    protected function unsupported(): array
    {
        return [];
    }

    abstract protected function noun(): string;

    protected function allowedFields(): array
    {
        return array_values(array_diff(['name', 'parent_id', 'code_prefix', 'default_unit_id', 'default_stock_group_id', 'sort_order'], $this->unsupported()));
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'code_prefix'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) === '' ? null : trim($this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $company = app(CurrentCompany::class)->id();
        $unique = Rule::unique($this->table(), 'name')->where('company_id', $company);
        if ($id = $this->route('id')) {
            $unique->ignore((int) $id);
        }

        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'required', 'string', 'max:80', $unique],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists($this->table(), 'id')->where('company_id', $company)],
            'code_prefix' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z0-9]{2,10}$/'],
            'default_unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('units', 'id')->where('company_id', $company)],
            'default_stock_group_id' => ['sometimes', 'nullable', 'integer', Rule::exists('stock_groups', 'id')->where('company_id', $company)],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        $noun = $this->noun();

        return [
            'name.required' => "Enter a {$noun} name",
            'name.max' => 'Names have at most 80 characters',
            'name.unique' => 'A '.$noun.' with this name already exists',
            'parent_id.exists' => "Choose an existing {$noun}",
            'code_prefix.regex' => 'Use 2 to 10 capital letters or digits, e.g. OIL',
        ];
    }

    /** Validated fields with sort_order null meaning 0 (the column is not nullable). */
    public function lookupData(): array
    {
        $data = $this->validated();
        if (array_key_exists('sort_order', $data)) {
            $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        }

        return $data;
    }
}
