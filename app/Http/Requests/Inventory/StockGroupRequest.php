<?php

namespace App\Http\Requests\Inventory;

/** POST/PATCH /stock-groups (D6): name, parent and order; stock groups have no code prefix or defaults. */
class StockGroupRequest extends TreeLookupRequest
{
    protected function table(): string
    {
        return 'stock_groups';
    }

    protected function noun(): string
    {
        return 'stock group';
    }

    protected function unsupported(): array
    {
        return ['code_prefix', 'default_unit_id', 'default_stock_group_id'];
    }
}
