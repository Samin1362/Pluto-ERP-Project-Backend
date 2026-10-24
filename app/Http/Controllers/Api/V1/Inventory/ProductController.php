<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Access\PermissionResolver;
use App\Domain\Inventory\ProductService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\ProductListRequest;
use App\Http\Requests\Inventory\ProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Queries\ProductQuery;
use App\Queries\ProductStockQuery;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /products (D1–D4, backlog P3.1-BE-05/06). Costs are masked per BR-052. */
class ProductController extends Controller
{
    use ReadsIfMatch;

    public function __construct(
        private readonly ProductService $products,
        private readonly ProductStockQuery $stock,
        private readonly ProductQuery $query,
        private readonly CurrentCompany $current,
        private readonly PermissionResolver $permissions,
    ) {}

    /** GET /products (D1, P3.1-BE-06): one page with stock figures from ProductQuery; costs masked per BR-052. */
    public function index(ProductListRequest $request): JsonResponse
    {
        $page = $this->query->page($request->validated());
        $rows = $page->getCollection()->keyBy('id');
        $products = Product::with(['category', 'unit', 'stockGroup', 'preferredSupplier'])->whereIn('id', $rows->keys())->get()->keyBy('id');
        $costs = $this->showCosts();

        return response()->json([
            'data' => $rows->map(fn ($r, $id) => (new ProductResource($products[$id], $r, $costs))->resolve($request))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** DELETE /products/{id} (P3.1-BE-06): unused items only (409 has_history otherwise). */
    public function destroy(int $id): Response
    {
        $this->products->delete(Product::findOrFail($id));

        return response()->noContent();
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, Product::findOrFail($id));
    }

    public function store(ProductRequest $request): JsonResponse
    {
        return $this->respond($request, $this->products->create($request->validated()), 201);
    }

    public function update(ProductRequest $request, int $id): JsonResponse
    {
        $updated = $this->products->update(Product::findOrFail($id), $request->validated(), $this->ifMatchVersion($request));

        return $this->respond($request, $updated);
    }

    private function respond(Request $request, Product $product, int $status = 200): JsonResponse
    {
        $product->load(['category', 'unit', 'stockGroup', 'preferredSupplier']);
        $resource = new ProductResource($product, $this->stock->totals([$product->id])[$product->id] ?? null, $this->showCosts(), $this->stock->levels($product->id));

        return response()->json(['data' => $resource->resolve($request)], $status)->setEtag((string) $product->lock_version, true);
    }

    private function showCosts(): bool
    {
        return $this->permissions->allows($this->current->membership(), 'inventory.view_costs');
    }
}
