<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\StrictRequest;
use Illuminate\Validation\Rule;

/** GET /warehouses/{id}/stock query (05 getWarehousesIdStock, D8; backlog P11.1-BE-01): LISTQ + below_reorder. */
class WarehouseStockRequest extends StrictRequest
{
    public const SORTS = ['name', '-name', 'code', '-code', 'qty_on_hand', '-qty_on_hand'];

    protected function allowedFields(): array
    {
        return ['page', 'per_page', 'sort', 'q', 'below_reorder'];
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'max:100'],
            'below_reorder' => ['sometimes', Rule::in(['true', 'false', '1', '0'])],
            'sort' => ['sometimes', Rule::in(self::SORTS)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
