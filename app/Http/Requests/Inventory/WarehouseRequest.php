<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * WarehouseInput (05 postWarehouses / patchWarehousesId, D7/D8, backlog P3.1-BE-04): POST needs code, name and branch;
 * PATCH is partial. Whether inventory_ledger_id is an inventory ledger under 1301 is checked by WarehouseService.
 */
class WarehouseRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['code', 'name', 'branch_id', 'address', 'manager_user_id', 'phone', 'inventory_ledger_id', 'is_default_for_sales', 'status', 'opens_on'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name', 'address', 'phone'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field)) === '' ? null : trim($this->input($field))]);
            }
        }
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper($this->input('code'))]);
        }
    }

    public function rules(): array
    {
        $company = app(CurrentCompany::class)->id();
        $req = $this->isMethod('POST') ? 'required' : 'sometimes';
        $unique = Rule::unique('warehouses', 'code')->where('company_id', $company);
        if ($id = $this->route('id')) {
            $unique->ignore((int) $id);
        }

        return [
            'code' => [$req, 'required', 'string', 'max:20', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', $unique],
            'name' => [$req, 'required', 'string', 'max:120'],
            'branch_id' => [$req, 'required', 'integer', Rule::exists('branches', 'id')->where('company_id', $company)->where('is_active', true)],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'manager_user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('company_users', 'user_id')->where('company_id', $company)->where('status', 'active')],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'inventory_ledger_id' => ['sometimes', 'nullable', 'integer'],
            'is_default_for_sales' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'planned', 'inactive'])],
            'opens_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Enter a warehouse code',
            'code.regex' => 'Use capital letters, digits and dashes, e.g. WH-NGJ',
            'code.unique' => 'Another warehouse already uses this code',
            'name.required' => 'Enter the warehouse name',
            'branch_id.required' => 'Choose the branch this warehouse belongs to',
            'branch_id.exists' => 'Choose an active branch',
            'manager_user_id.exists' => 'The manager must be an active member of this company',
        ];
    }
}
