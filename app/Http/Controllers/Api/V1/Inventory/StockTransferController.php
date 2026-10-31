<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Access\PermissionResolver;
use App\Domain\Inventory\StockTransferService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockDocumentListRequest;
use App\Http\Requests\Inventory\StockTransferRequest;
use App\Http\Requests\Inventory\TransferReceiveRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\StockBalance;
use App\Models\StockTransfer;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /stock-transfers (D11; backlog P11.1-BE-03): drafts, the list, dispatch, receive and cancel. */
class StockTransferController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly StockTransferService $transfers, private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    /** GET /stock-transfers: newest first; `warehouse_id` matches either end; search covers the number and products. */
    public function index(StockDocumentListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = StockTransfer::query()->with(['from:id,name', 'to:id,name', 'lines']);
        if (isset($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (isset($f['warehouse_id'])) {
            $q->where(fn ($w) => $w->where('from_warehouse_id', $f['warehouse_id'])->orWhere('to_warehouse_id', $f['warehouse_id']));
        }
        if (isset($f['date_from'])) {
            $q->whereDate('dispatch_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('dispatch_date', '<=', $f['date_to']);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('number', 'like', $term)->orWhereExists(fn ($e) => $e->from('stock_transfer_lines as l')
                ->join('products as p', 'p.id', '=', 'l.product_id')->whereColumn('l.stock_transfer_id', 'stock_transfers.id')
                ->where(fn ($p) => $p->where('p.code', 'like', $term)->orWhere('p.name', 'like', $term))));
        }
        $sort = $f['sort'] ?? '-created_at';
        $dir = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $page = $q->orderBy(ltrim($sort, '-'), $dir)->orderBy('id', $dir)->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (StockTransfer $t) => (new StockTransferResource($t, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(StockTransferRequest $request): JsonResponse
    {
        return $this->respond($request, $this->transfers->create($request->validated()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, StockTransfer::findOrFail($id));
    }

    public function update(StockTransferRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->transfers->update(StockTransfer::findOrFail($id), $request->validated(), $version));
    }

    public function destroy(int $id): Response
    {
        $this->transfers->delete(StockTransfer::findOrFail($id));

        return response()->noContent();
    }

    public function send(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->transfers->dispatch(StockTransfer::findOrFail($id)));
    }

    public function receive(TransferReceiveRequest $request, int $id): JsonResponse
    {
        $in = $request->validated();

        return $this->respond($request, $this->transfers->receive(StockTransfer::findOrFail($id), $in['received_date'], $in['lines'] ?? null));
    }

    public function cancel(ReasonRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->transfers->cancel(StockTransfer::findOrFail($id), $request->validated('reason')));
    }

    private function respond(Request $request, StockTransfer $t, int $status = 200): JsonResponse
    {
        $t->loadMissing(['lines.product.unit', 'from:id,name', 'to:id,name', 'createdBy']);
        $costs = $this->permissions->allows($this->current->membership(), 'inventory.view_costs');
        $available = $t->status === 'draft'
            ? StockBalance::where('warehouse_id', $t->from_warehouse_id)->whereIn('product_id', $t->lines->pluck('product_id'))->pluck('qty_on_hand', 'product_id')->all() : [];

        return response()->json(['data' => (new StockTransferResource($t, $costs, value: $costs ? $this->transfers->valueAtCost($t) : null,
            loss: $costs && $t->status === 'received' ? $this->transfers->transitLoss($t) : null, available: $available))->resolve($request)], $status)
            ->setEtag((string) $t->lock_version, true);
    }
}
