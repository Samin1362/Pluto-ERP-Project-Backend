<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Requests\Inventory\StockGroupRequest;
use App\Models\StockGroup;
use Illuminate\Http\JsonResponse;

/** /stock-groups (D6, backlog P3.1-BE-03): the reporting hierarchy of products (GAP-20), with items and value. */
class StockGroupController extends TreeLookupController
{
    protected function model(): string
    {
        return StockGroup::class;
    }

    protected function productColumn(): string
    {
        return 'stock_group_id';
    }

    protected function notFound(): string
    {
        return 'This stock group does not exist or was deleted.';
    }

    public function store(StockGroupRequest $request): JsonResponse
    {
        return $this->storeNode($request);
    }

    public function update(StockGroupRequest $request, int $id): JsonResponse
    {
        return $this->updateNode($request, $id);
    }
}
