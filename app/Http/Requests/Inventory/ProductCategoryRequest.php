<?php

namespace App\Http\Requests\Inventory;

/** POST/PATCH /product-categories (D5): name, parent (two levels), code prefix and defaults for new products. */
class ProductCategoryRequest extends TreeLookupRequest
{
    protected function table(): string
    {
        return 'product_categories';
    }

    protected function noun(): string
    {
        return 'category';
    }
}
