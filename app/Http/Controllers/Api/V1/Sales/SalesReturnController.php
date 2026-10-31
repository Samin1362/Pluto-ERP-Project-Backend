<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Access\PermissionResolver;
use App\Domain\Sales\SalesReturnService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReasonRequest;
use App\Http\Requests\Sales\SalesReturnListRequest;
use App\Http\Requests\Sales\SalesReturnRequest;
use App\Http\Resources\SalesReturnResource;
use App\Models\SalesReturn;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /sales-returns (E8, E15; backlog P10.3-BE-01): drafts, the list, posting (creates the draft credit note) and cancelling. */
class SalesReturnController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly SalesReturnService $returns, private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    /** GET /sales-returns (E15): newest first; search covers the SR number, the invoice number and the customer's name. */
    public function index(SalesReturnListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = SalesReturn::query()->with(['party', 'invoice:id,number', 'creditNote'])->select('sales_returns.*');
        foreach (['party_id', 'status'] as $col) {
            if (isset($f[$col])) {
                $q->where("sales_returns.{$col}", $f[$col]);
            }
        }
        if (isset($f['reason'])) {
            $q->whereExists(fn ($w) => $w->from('sales_return_lines as rl')->whereColumn('rl.sales_return_id', 'sales_returns.id')->where('rl.reason', $f['reason']));
        }
        if (isset($f['date_from'])) {
            $q->whereDate('sales_returns.return_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('sales_returns.return_date', '<=', $f['date_to']);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->leftJoin('parties as cp', 'cp.id', '=', 'sales_returns.party_id')->leftJoin('sales_invoices as si', 'si.id', '=', 'sales_returns.sales_invoice_id')
                ->where(fn ($w) => $w->where('sales_returns.number', 'like', $term)->orWhere('si.number', 'like', $term)->orWhere('cp.name', 'like', $term));
        }
        $sort = $f['sort'] ?? '-return_date';
        $page = $q->orderBy('sales_returns.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('sales_returns.id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (SalesReturn $r) => (new SalesReturnResource($r, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(SalesReturnRequest $request): JsonResponse
    {
        return $this->respond($request, $this->returns->create($request->validated()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, SalesReturn::findOrFail($id));
    }

    public function update(SalesReturnRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->returns->update(SalesReturn::findOrFail($id), $request->validated(), $version));
    }

    public function destroy(int $id): Response
    {
        $this->returns->delete(SalesReturn::findOrFail($id));

        return response()->noContent();
    }

    public function post(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->returns->post(SalesReturn::findOrFail($id)));
    }

    public function cancel(ReasonRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->returns->cancel(SalesReturn::findOrFail($id), $request->validated('reason')));
    }

    private function respond(Request $request, SalesReturn $r, int $status = 200): JsonResponse
    {
        $r->loadMissing(['lines', 'party', 'invoice:id,number', 'creditNote', 'createdBy']);
        $costs = $this->permissions->allows($this->current->membership(), 'inventory.view_costs');

        return response()->json(['data' => (new SalesReturnResource($r, $costs))->resolve($request)], $status)->setEtag((string) $r->lock_version, true);
    }
}
