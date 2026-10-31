<?php

namespace App\Http\Requests\Banking;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** IeCategoryInput (05 postIeCategories / patchIeCategoriesId; I7; backlog P13.1-BE-02). */
class IeCategoryRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['kind', 'name', 'description', 'ledger_id', 'create_ledger', 'monthly_budget', 'warn_at_percent'];
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['income', 'expense'])],
            'name' => ['required', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ledger_id' => ['sometimes', 'nullable', 'integer', Rule::exists('ledgers', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'create_ledger' => ['sometimes', 'boolean'],
            'monthly_budget' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'warn_at_percent' => ['sometimes', 'string', 'regex:/^\d{1,3}(\.\d{1,4})?$/', function ($attr, $v, $fail) {
                if ((float) $v > 100) {
                    $fail('The warning level is a percentage from 0 to 100');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Name the category', 'monthly_budget.regex' => 'Enter the budget in taka, e.g. 50000.00',
            'warn_at_percent.regex' => 'The warning level is a percentage from 0 to 100'];
    }
}
