<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** AccountGroupInput (05 postAccountGroups / patchAccountGroupsId; backlog P7.1-BE-01). PATCH changes only the fields sent. */
class AccountGroupRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['code', 'name', 'parent_id', 'cash_flow_category', 'sort_order', 'used_in_cash_vouchers'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name'] as $f) {
            if (is_string($this->input($f))) {
                $this->merge([$f => trim($this->input($f))]);
            }
        }
    }

    public function rules(): array
    {
        $required = $this->isMethod('PATCH') ? 'sometimes' : 'required';
        $company = app(CurrentCompany::class)->id();
        $code = Rule::unique('account_groups', 'code')->where('company_id', $company);
        if ($this->isMethod('PATCH')) {
            $code->ignore((int) $this->route('id'));
        }

        return [
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Za-z0-9][A-Za-z0-9.\-]*$/', $code],
            'name' => [$required, 'string', 'max:120'],
            'parent_id' => [$required, 'integer', Rule::exists('account_groups', 'id')->where('company_id', $company)],
            'cash_flow_category' => ['sometimes', 'nullable', Rule::in(['operating', 'investing', 'financing', 'cash'])],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'used_in_cash_vouchers' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.unique' => 'Another group already uses this code',
            'code.regex' => 'Use letters, digits, dots or dashes',
            'parent_id.exists' => 'Choose a group of this company',
        ];
    }
}
