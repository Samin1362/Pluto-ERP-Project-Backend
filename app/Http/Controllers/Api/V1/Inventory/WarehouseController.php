<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Access\PermissionResolver;
use App\Domain\Inventory\WarehouseService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\WarehouseRequest;
use App\Http\Resources\WarehouseResource;
use App\Models\Warehouse;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET/POST /warehouses, GET/PATCH /warehouses/{id} (D7/D8, backlog P3.1-BE-04). GET /warehouses/{id}/stock comes
 * with P11. Stock figures stay 0 until purchase bills post (P4).
 */
class WarehouseController extends Controller
{
    use ReadsIfMatch;

    public function __construct(
        private readonly WarehouseService $warehouses,
        private readonly CurrentCompany $current,
        private readonly PermissionResolver $permissions,
    ) {}

    /** Every warehouse: active and planned first, then by name. */
    public function index(Request $request): JsonResponse
    {
        if ($unknown = array_keys($request->query())) {
            throw new ApiProblem('validation_failed', errors: array_fill_keys($unknown, ['This parameter is not part of the API.']));
        }
        $rows = Warehouse::with('manager')->orderByRaw("status = 'inactive'")->orderBy('name')->get();
        $stats = $this->stats($rows->pluck('id')->all());

        return response()->json([
            'data' => $rows->map(fn ($w) => $this->resource($w, $stats)->resolve($request))->all(),
            'meta' => ['current_page' => 1, 'per_page' => max($rows->count(), 1), 'total' => $rows->count(), 'last_page' => 1],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, Warehouse::findOrFail($id));
    }

    public function store(WarehouseRequest $request): JsonResponse
    {
        return $this->respond($request, $this->warehouses->create($request->validated()), 201);
    }

    public function update(WarehouseRequest $request, int $id): JsonResponse
    {
        $updated = $this->warehouses->update(Warehouse::findOrFail($id), $request->validated(), $this->ifMatchVersion($request));

        return $this->respond($request, $updated);
    }

    private function respond(Request $request, Warehouse $warehouse, int $status = 200): JsonResponse
    {
        $warehouse->load('manager');

        return response()->json(['data' => $this->resource($warehouse, $this->stats([$warehouse->id]))->resolve($request)], $status)
            ->setEtag((string) $warehouse->lock_version, true);
    }

    private function resource(Warehouse $w, array $stats): WarehouseResource
    {
        return new WarehouseResource($w, $stats[$w->id] ?? null, $this->permissions->allows($this->current->membership(), 'inventory.view_costs'));
    }

    /**
     * Per warehouse: stock value and products on hand (stock_balances), and products at or below their reorder level
     * (product_warehouse_settings with a level > 0). Sums stay DECIMAL in MySQL.
     */
    private function stats(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $c = $this->current->id();
        $stock = DB::table('stock_balances')->where('company_id', $c)->whereIn('warehouse_id', $ids)->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, CAST(COALESCE(SUM(total_value), 0) AS DECIMAL(18,2)) as value, SUM(qty_on_hand <> 0) as products')
            ->get()->keyBy('warehouse_id');
        $below = DB::table('product_warehouse_settings as s')->where('s.company_id', $c)->whereIn('s.warehouse_id', $ids)->where('s.reorder_level', '>', 0)
            ->join('products as p', fn ($j) => $j->on('p.id', '=', 's.product_id')->whereNull('p.deleted_at')->where('p.is_active', true))
            ->leftJoin('stock_balances as b', fn ($j) => $j->on('b.product_id', '=', 's.product_id')->on('b.warehouse_id', '=', 's.warehouse_id'))
            ->whereRaw('COALESCE(b.qty_on_hand, 0) <= s.reorder_level')
            ->groupBy('s.warehouse_id')->selectRaw('s.warehouse_id, COUNT(*) as n')->pluck('n', 'warehouse_id');
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = (object) ['value' => $stock[$id]->value ?? '0.00', 'products' => (int) ($stock[$id]->products ?? 0), 'below_reorder' => (int) ($below[$id] ?? 0)];
        }

        return $out;
    }
}
