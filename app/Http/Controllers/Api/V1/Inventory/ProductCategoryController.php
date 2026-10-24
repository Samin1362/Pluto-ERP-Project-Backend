<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Requests\Inventory\ProductCategoryRequest;
use App\Models\ProductCategory;
use Illuminate\Http\JsonResponse;

/** /product-categories (D5, backlog P3.1-BE-02): two-level tree with code prefixes, item counts and stock value. */
class ProductCategoryController extends TreeLookupController
{
    protected function model(): string
    {
        return ProductCategory::class;
    }

    protected function productColumn(): string
    {
        return 'category_id';
    }

    protected function notFound(): string
    {
        return 'This category does not exist or was deleted.';
    }

    public function store(ProductCategoryRequest $request): JsonResponse
    {
        return $this->storeNode($request);
    }

    public function update(ProductCategoryRequest $request, int $id): JsonResponse
    {
        return $this->updateNode($request, $id);
    }
}
