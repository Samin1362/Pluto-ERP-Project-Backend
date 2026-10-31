<?php

namespace App\Http\Controllers\Api\V1\Purchases;

use App\Domain\Access\PermissionResolver;
use App\Domain\Purchases\PurchaseReturnService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\PurchaseReturnListRequest;
use App\Http\Requests\Purchases\PurchaseReturnRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\PurchaseReturn;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /purchase-returns (F6, F13; backlog P10.4-BE-01): drafts, the list, posting (with its debit note) and cancelling. */
class PurchaseReturnController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly PurchaseReturnService $returns, private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    /** GET /purchase-returns (F13): newest first; search covers the PR number, the bill number and the supplier's name. */
    public function index(PurchaseReturnListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = PurchaseReturn::query()->with(['party', 'bill:id,number', 'debitNote'])->select('purchase_returns.*');
        foreach (['party_id', 'status'] as $col) {
            if (isset($f[$col])) {
                $q->where("purchase_returns.{$col}", $f[$col]);
            }
        }
        if (isset($f['reason'])) {
            $q->whereExists(fn ($w) => $w->from('purchase_return_lines as rl')->whereColumn('rl.purchase_return_id', 'purchase_returns.id')->where('rl.reason', $f['reason']));
        }
        if (isset($f['date_from'])) {
            $q->whereDate('purchase_returns.return_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('purchase_returns.return_date', '<=', $f['date_to']);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->leftJoin('parties as sp', 'sp.id', '=', 'purchase_returns.party_id')->leftJoin('purchase_bills as pb', 'pb.id', '=', 'purchase_returns.purchase_bill_id')
                ->where(fn ($w) => $w->where('purchase_returns.number', 'like', $term)->orWhere('pb.number', 'like', $term)->orWhere('sp.name', 'like', $term));
        }
        $sort = $f['sort'] ?? '-return_date';
        $page = $q->orderBy('purchase_returns.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('purchase_returns.id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (PurchaseReturn $r) => (new PurchaseReturnResource($r, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(PurchaseReturnRequest $request): JsonResponse
    {
        return $this->respond($request, $this->returns->create($request->validated()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, PurchaseReturn::findOrFail($id));
    }

    public function update(PurchaseReturnRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->returns->update(PurchaseReturn::findOrFail($id), $request->validated(), $version));
    }

    public function destroy(int $id): Response
    {
        $this->returns->delete(PurchaseReturn::findOrFail($id));

        return response()->noContent();
    }

    public function post(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->returns->post(PurchaseReturn::findOrFail($id)));
    }

    public function cancel(ReasonRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->returns->cancel(PurchaseReturn::findOrFail($id), $request->validated('reason')));
    }

    private function respond(Request $request, PurchaseReturn $r, int $status = 200): JsonResponse
    {
        $r->loadMissing(['lines', 'party', 'bill:id,number', 'debitNote', 'createdBy']);
        $costs = $this->permissions->allows($this->current->membership(), 'inventory.view_costs');

        return response()->json(['data' => (new PurchaseReturnResource($r, $costs))->resolve($request)], $status)->setEtag((string) $r->lock_version, true);
    }
}
