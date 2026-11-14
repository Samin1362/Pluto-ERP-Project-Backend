<?php

namespace App\Http\Requests\Lc;

use App\Http\Requests\StrictRequest;
use App\Support\CurrentCompany;
use Illuminate\Validation\Rule;

/** POST /letters-of-credit/{id}/items (LcItemsInput; PEP-487): the items of an LC that has none, as LcLineInput, and where they arrive. */
class LcItemsRequest extends StrictRequest
{
    protected function allowedFields(): array
    {
        return ['lines', 'warehouse_id'];
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['sometimes', 'nullable', 'integer', Rule::exists('warehouses', 'id')->where('company_id', app(CurrentCompany::class)->id())],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:product_id,quantity,unit_id,unit_cost,description'],
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('company_id', app(CurrentCompany::class)->id())->whereNull('deleted_at')],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,3})?$/', 'not_regex:/^0+(\.0+)?$/'],
            'lines.*.unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('units', 'id')->where('company_id', app(CurrentCompany::class)->id())], // one of the item's units: checked when the items are priced (BR-069)
            'lines.*.unit_cost' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'lines.*.description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'Add at least one item',
            'lines.min' => 'Add at least one item',
            'lines.*.product_id.required' => 'Choose an item',
            'lines.*.product_id.exists' => 'Choose one of your items',
            'lines.*.quantity.required' => 'Enter the quantity',
            'lines.*.quantity.regex' => 'Enter a quantity with up to 3 decimals',
            'lines.*.quantity.not_regex' => 'The quantity must be more than 0',
            'lines.*.unit_cost.required' => 'Enter the unit cost',
            'lines.*.unit_cost.regex' => 'Enter a cost of 0 or more with up to 4 decimals',
        ];
    }
}
