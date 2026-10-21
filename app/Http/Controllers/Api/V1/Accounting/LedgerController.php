<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Domain\Access\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\LedgerListRequest;
use App\Http\Resources\LedgerResource;
use App\Queries\LedgerQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;

/**
 * GET /ledgers (backlog P1.1-BE-03): the chart list for accounting.view, and the ledger picker for everyone else.
 * Without accounting.view a `purpose` is required and must be allowed by one of the caller's create/edit
 * permissions; such callers get matching active posting ledgers without balances (R-26, docs 12 §3).
 */
class LedgerController extends Controller
{
    public function __construct(private readonly LedgerQuery $query, private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

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
}
