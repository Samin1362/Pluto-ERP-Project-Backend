<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounting\LedgerService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\LedgerEntriesRequest;
use App\Http\Requests\Accounting\LedgerListRequest;
use App\Http\Requests\Accounting\LedgerRequest;
use App\Http\Resources\LedgerResource;
use App\Models\Ledger;
use App\Queries\LedgerEntriesQuery;
use App\Queries\LedgerQuery;
use App\Queries\PartyAccountQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /ledgers. GET /ledgers (backlog P1.1-BE-03): the chart list for accounting.view, and the ledger picker for everyone
 * else. Without accounting.view a `purpose` is required and must be allowed by one of the caller's create/edit
 * permissions; such callers get matching active posting ledgers without balances (R-26, docs 12 §3).
 * Create, details with the FY summary, edit and deactivate (H4/H5; backlog P7.1-BE-02).
 */
class LedgerController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly LedgerQuery $query, private readonly CurrentCompany $current, private readonly PermissionResolver $permissions,
        private readonly LedgerService $ledgers, private readonly LedgerEntriesQuery $statements) {}

    public function index(LedgerListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $membership = $this->current->membership();
        $accounting = $this->permissions->allows($membership, 'accounting.view');
        if (! $accounting) {
            $purpose = $f['purpose'] ?? null;
            if ($purpose === null) {
                throw new ApiProblem('purpose_required');
            }
            $allowed = $purpose !== 'any' && collect(LedgerQuery::PURPOSES[$purpose]['permissions'])
                ->contains(fn ($p) => $this->permissions->allows($membership, $p));
            if (! $allowed) {
                throw new ApiProblem('permission_denied', "Your role cannot choose ledgers for {$purpose}.");
            }
            $f = ['purpose' => $purpose, 'active' => 'true'] + array_intersect_key($f, array_flip(['q', 'sort', 'page', 'per_page']));
        }
        $page = $this->query->list($f, $accounting)->paginate((int) ($f['per_page'] ?? 25));
        $paths = $this->query->groupPaths();

        return response()->json([
            'data' => $page->getCollection()->map(fn ($l) => (new LedgerResource($l, $paths, $accounting))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(LedgerRequest $request): JsonResponse
    {
        $ledger = $this->ledgers->create($request->validated());

        return $this->respond($request, $ledger->id, 201)->header('Location', url("/api/v1/ledgers/{$ledger->id}"));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $id);
    }

    public function update(LedgerRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);
        $this->ledgers->update(Ledger::findOrFail($id), $request->validated(), $version);

        return $this->respond($request, $id);
    }

    /** GET /ledgers/{id}/entries (H7; P7.1-BE-03): LedgerStatement for the range, this financial year to today by default. */
    public function entries(LedgerEntriesRequest $request, int $id): JsonResponse
    {
        $f = $request->validated();
        $ledger = Ledger::findOrFail($id);
        [$from, $to] = PartyAccountQuery::range($f['date_from'] ?? null, $f['date_to'] ?? null);

        return response()->json(['data' => $this->statements->statement($ledger->id, $from, $to, $f['narration'] ?? null, (int) ($f['page'] ?? 1))]);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        $this->ledgers->deactivate(Ledger::findOrFail($id));

        return $this->respond($request, $id);
    }

    private function respond(Request $request, int $id, int $status = 200): JsonResponse
    {
        $ledger = $this->query->find($id);
        $data = (new LedgerResource($ledger, $this->query->groupPaths(), true, $this->query->fySummary($id)))->resolve($request);

        return response()->json(['data' => $data], $status)->setEtag((string) $ledger->lock_version, true);
    }
}
