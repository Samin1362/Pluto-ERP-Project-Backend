<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/**
 * ProductInput (05 postProducts / patchProductsId, D2/D3, backlog P3.1-BE-05). POST needs kind, name, unit, selling
 * price and income account; PATCH is partial. On-hand quantity and average cost are not fields at all, so a PATCH
 * that sends them is a 422 like any unknown field (TC-INV-005). Ledger natures and service rules are checked by
 * ProductService. Opening stock (opening_qty with opening_unit_cost) is taken only when the item is created (06 §11.2,
 * BR-028; P11.1-BE-05); afterwards stock changes through stock documents, so a PATCH must send 0.
 */
class ProductRequest extends StrictRequest
{
    private const MONEY = '/^\d{1,16}(\.\d{1,2})?$/';

    private const QTY = '/^\d{1,15}(\.\d{1,3})?$/';

    private const COST = '/^\d{1,14}(\.\d{1,4})?$/';

    protected function allowedFields(): array
    {
        return ['item_type', 'name', 'code', 'category_id', 'unit_id', 'barcode', 'last_purchase_cost', 'selling_price', 'wholesale_price',
            'tax_rule_id', 'income_ledger_id', 'expense_ledger_id', 'stock_group_id', 'preferred_supplier_id', 'is_active', 'warehouses'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['name', 'code', 'barcode'] as $field) {
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
        $creating = $this->isMethod('POST');
        $req = $creating ? 'required' : 'sometimes';
        $id = $this->route('id');
        $code = Rule::unique('products', 'code')->where('company_id', $company);
        $barcode = Rule::unique('products', 'barcode')->where('company_id', $company);
        if ($id) {
            $code->ignore((int) $id);
            $barcode->ignore((int) $id);
        }
        $exists = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $company);

        return [
            'item_type' => [$req, Rule::in(['product', 'service'])],
            'name' => [$req, 'required', 'string', 'max:190'],
            'code' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[A-Z0-9][A-Z0-9-]*$/', $code],
            'category_id' => ['sometimes', 'nullable', 'integer', $exists('product_categories')],
            'unit_id' => [$req, 'required', 'integer', $exists('units')],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:40', $barcode],
            'last_purchase_cost' => ['sometimes', 'nullable', 'string', 'regex:'.self::COST],
            'selling_price' => [$req, 'required', 'string', 'regex:'.self::MONEY],
            'wholesale_price' => ['sometimes', 'nullable', 'string', 'regex:'.self::MONEY],
            'tax_rule_id' => ['sometimes', 'nullable', 'integer', $exists('tax_rules')->where('is_active', true)],
            'income_ledger_id' => [$req, 'required', 'integer', $exists('ledgers')],
            'expense_ledger_id' => ['sometimes', 'nullable', 'integer', $exists('ledgers')],
            'stock_group_id' => ['sometimes', 'nullable', 'integer', $exists('stock_groups')],
            'preferred_supplier_id' => ['sometimes', 'nullable', 'integer', $exists('parties')->where('party_type', 'supplier')->where('status', 'active')->whereNull('deleted_at')],
            'is_active' => ['sometimes', 'boolean'],
            'warehouses' => ['sometimes', 'array', 'max:100'],
            'warehouses.*' => ['array:warehouse_id,reorder_level,reorder_qty,opening_qty,opening_unit_cost'],
            'warehouses.*.warehouse_id' => ['required', 'integer', 'distinct', $exists('warehouses')],
            'warehouses.*.reorder_level' => ['sometimes', 'nullable', 'string', 'regex:'.self::QTY],
            'warehouses.*.reorder_qty' => ['sometimes', 'nullable', 'string', 'regex:'.self::QTY],
            'warehouses.*.opening_qty' => ['sometimes', 'nullable', 'string', 'regex:'.($creating ? self::QTY : '/^0+(\.0{1,3})?$/')],
            'warehouses.*.opening_unit_cost' => ['sometimes', 'nullable', 'string', 'regex:'.self::COST],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Enter the item name',
            'unit_id.required' => 'Choose a unit',
            'selling_price.required' => 'Enter the selling price',
            'selling_price.regex' => 'Enter an amount of 0 or more with up to 2 decimals',
            'wholesale_price.regex' => 'Enter an amount of 0 or more with up to 2 decimals',
            'last_purchase_cost.regex' => 'Enter a cost of 0 or more with up to 4 decimals',
            'income_ledger_id.required' => 'Choose the income account',
            'code.unique' => 'Another item already uses this code',
            'code.regex' => 'Use capital letters, digits and dashes, e.g. OIL-0001',
            'barcode.unique' => 'Another item already uses this barcode',
            'warehouses.*.warehouse_id.distinct' => 'Each warehouse can appear only once',
            'warehouses.*.opening_qty.regex' => $this->isMethod('POST') ? 'Enter a quantity with up to 3 decimals'
                : 'Opening stock is entered when the item is created; use a stock adjustment now',
        ];
    }
}
