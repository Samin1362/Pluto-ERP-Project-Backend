<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Access\PermissionResolver;
use App\Domain\Inventory\CountSheetReader;
use App\Domain\Inventory\StockAdjustmentService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\CountSheetRequest;
use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Http\Requests\Inventory\StockDocumentListRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Resources\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /stock-adjustments (D10; backlog P11.1-BE-02): drafts, the list, posting, reversal and the count-sheet upload. */
class StockAdjustmentController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly StockAdjustmentService $adjustments, private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    /** GET /stock-adjustments: newest first; search covers the number and the products' code and name. */
    public function index(StockDocumentListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = StockAdjustment::query()->with('warehouse:id,name')->withCount('lines');
        foreach (['status', 'warehouse_id'] as $col) {
            if (isset($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (isset($f['date_from'])) {
            $q->whereDate('adjustment_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('adjustment_date', '<=', $f['date_to']);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('number', 'like', $term)->orWhereExists(fn ($e) => $e->from('stock_adjustment_lines as l')
                ->join('products as p', 'p.id', '=', 'l.product_id')->whereColumn('l.stock_adjustment_id', 'stock_adjustments.id')
                ->where(fn ($p) => $p->where('p.code', 'like', $term)->orWhere('p.name', 'like', $term))));
        }
        $sort = $f['sort'] ?? '-adjustment_date';
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $page = $q->orderBy(ltrim($sort, '-'), $dir)->orderBy('id', $dir)->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));
        $costs = $this->costs();

        return response()->json([
            'data' => $page->getCollection()->map(fn (StockAdjustment $a) => (new StockAdjustmentResource($a, $costs, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(StockAdjustmentRequest $request): JsonResponse
    {
        return $this->respond($request, $this->adjustments->create($request->validated()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, StockAdjustment::findOrFail($id));
    }

    public function update(StockAdjustmentRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->adjustments->update(StockAdjustment::findOrFail($id), $request->validated(), $version));
    }

    public function destroy(int $id): Response
    {
        $this->adjustments->delete(StockAdjustment::findOrFail($id));

        return response()->noContent();
    }

    public function post(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->adjustments->post(StockAdjustment::findOrFail($id)));
    }

    public function reverse(ReasonRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->adjustments->reverse(StockAdjustment::findOrFail($id), $request->validated('reason')));
    }

    /** POST /stock-adjustments/count-sheet: the uploaded CSV matched to products (CountSheetPreview); nothing is saved. */
    public function countSheet(CountSheetRequest $request, CountSheetReader $reader): JsonResponse
    {
        return response()->json(['data' => $reader->read($request->file('file'))]);
    }

    private function respond(Request $request, StockAdjustment $a, int $status = 200): JsonResponse
    {
        $a->loadMissing(['lines.product.unit', 'warehouse:id,name', 'createdBy']);
        $costs = $this->costs();

        return response()->json(['data' => (new StockAdjustmentResource($a, $costs, preview: $costs ? $this->adjustments->postingPreview($a) : null))->resolve($request)], $status)
            ->setEtag((string) $a->lock_version, true);
    }

    private function costs(): bool
    {
        return $this->permissions->allows($this->current->membership(), 'inventory.view_costs');
    }
}
