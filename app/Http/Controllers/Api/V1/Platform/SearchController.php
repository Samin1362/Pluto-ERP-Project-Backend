<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Access\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\SearchRequest;
use App\Queries\SearchQuery;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;

/** GET /search (L1, L2; FR-012; backlog P14.1-BE-03): any member; each type needs its module's view permission. */
class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, SearchQuery $query, CurrentCompany $current, PermissionResolver $permissions): JsonResponse
    {
        $f = $request->validated();
        $member = $current->membership();

        return response()->json(['data' => $query->search($current->id(), $f['q'], $f['type'] ?? null, (int) ($f['limit'] ?? 5),
            fn (string $p) => $member !== null && $permissions->allows($member, $p))]);
    }
}
