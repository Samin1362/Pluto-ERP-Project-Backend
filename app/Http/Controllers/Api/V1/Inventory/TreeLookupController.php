<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Access\PermissionResolver;
use App\Domain\Inventory\CatalogueService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\LookupListRequest;
use App\Http\Requests\Inventory\TreeLookupRequest;
use App\Models\ProductCategory;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared list/create/edit/delete for the two inventory trees (P3.1-BE-02/03). The list is the tree flattened
 * parent-first (by sort_order, then name) unless `sort` asks for name order; each row is a Lookup with its direct
 * product count and their stock value (omitted without inventory.view_costs, BR-052).
 */
abstract class TreeLookupController extends Controller
{
    public function __construct(
        protected readonly CatalogueService $catalogue,
        private readonly CurrentCompany $current,
        private readonly PermissionResolver $permissions,
    ) {}

    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** products.<column> that points at this tree. */
    abstract protected function productColumn(): string;

    abstract protected function notFound(): string;

    public function index(LookupListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $class = $this->model();
        $nodes = $class::orderBy('sort_order')->orderBy('name')->get();
        $rows = isset($f['sort']) ? $nodes->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE, $f['sort'] === '-name')->values() : $this->flatten($nodes);
        if (($f['q'] ?? '') !== '') {
            $rows = $rows->filter(fn ($n) => mb_stripos($n->name, $f['q']) !== false)->values();
        }
        $perPage = (int) ($f['per_page'] ?? 25);
        $page = (int) ($f['page'] ?? 1);
        $slice = $rows->slice(($page - 1) * $perPage, $perPage)->values();
        $stats = $this->stats($slice->pluck('id')->all());

        return response()->json([
            'data' => $slice->map(fn ($n) => $this->lookup($n, $stats))->all(),
            'meta' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $rows->count(), 'last_page' => max(1, (int) ceil($rows->count() / $perPage))],
        ]);
    }

    protected function storeNode(TreeLookupRequest $request): JsonResponse
    {
        $node = $this->catalogue->createNode($this->model(), $request->lookupData());

        return response()->json(['data' => $this->lookup($node, $this->stats([$node->id]))], 201);
    }

    protected function updateNode(TreeLookupRequest $request, int $id): JsonResponse
    {
        $node = $this->catalogue->updateNode($this->find($id), $request->lookupData());

        return response()->json(['data' => $this->lookup($node, $this->stats([$node->id]))]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->catalogue->deleteNode($this->find($id));

        return response()->json(null, 204);
    }

    private function find(int $id): Model
    {
        return $this->model()::find($id) ?? throw new ApiProblem('not_found', $this->notFound());
    }

    /** Parent-first order: each top-level node followed by its descendants. */
    private function flatten(Collection $nodes): Collection
    {
        $children = $nodes->groupBy(fn ($n) => $n->parent_id ?? 0);
        $out = collect();
        $walk = function (int $parent) use (&$walk, $children, $out) {
            foreach ($children->get($parent, []) as $n) {
                $out->push($n);
                $walk($n->id);
            }
        };
        $walk(0);

        return $out;
    }

    /** Direct product count and stock value per node id. */
    private function stats(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $col = 'products.'.$this->productColumn();

        // The value is summed and formatted by MySQL (DECIMAL), never through a PHP float.
        return DB::table('products')->where('products.company_id', $this->current->id())->whereNull('products.deleted_at')->whereIn($col, $ids)
            ->leftJoin('stock_balances', 'stock_balances.product_id', '=', 'products.id')
            ->groupBy($col)
            ->selectRaw("{$col} as node_id, COUNT(DISTINCT products.id) as items, CAST(COALESCE(SUM(stock_balances.total_value), 0) AS DECIMAL(18,2)) as value")
            ->get()->keyBy('node_id')->all();
    }

    protected function lookup(Model $n, array $stats): array
    {
        $s = $stats[$n->id] ?? null;
        $row = ['id' => $n->id, 'name' => $n->name, 'parent_id' => $n->parent_id];
        if ($n instanceof ProductCategory) {
            $row['code_prefix'] = $n->code_prefix;
        }
        $row['items_count'] = (int) ($s->items ?? 0);
        if ($this->permissions->allows($this->current->membership(), 'inventory.view_costs')) {
            $row['stock_value'] = (string) ($s->value ?? '0.00');
        }

        return $row;
    }
}
