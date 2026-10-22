<?php

namespace App\Http\Controllers\Api\V1\Parties;

use App\Domain\Parties\PartyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\BulkIdsRequest;
use App\Http\Requests\Parties\PartyGroupRequest;
use App\Http\Requests\Parties\PartyListRequest;
use App\Http\Requests\Parties\PartyRequest;
use App\Http\Resources\PartyResource;
use App\Models\Party;
use App\Queries\PartyQuery;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Customers and suppliers: B1 list, B2 add/edit, B3 details (05 Parties tag). Permissions are checked by route
 * middleware; tenancy by the BelongsToCompany scope, so another company's id is a plain 404 (BR-037).
 */
class PartyController extends Controller
{
    public function __construct(private readonly PartyService $parties, private readonly PartyQuery $query) {}

    public function index(PartyListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $page = $this->query->list($f)->paginate((int) ($f['per_page'] ?? 25));

        return response()->json([
            'data' => PartyResource::collection($page->getCollection())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $party = $this->find($id);

        return $this->partyResponse($request, $party);
    }

    public function store(PartyRequest $request): JsonResponse
    {
        $r = $this->parties->create($request->partyData());

        return $this->partyResponse($request, $r['party'], 201, $r['warnings'])
            ->header('Location', url('/api/v1/parties/'.$r['party']->id));
    }

    /** POST /party-groups: the group shape is the one GET /party-groups lists (no single-group endpoint, so no Location). */
    public function storeGroup(PartyGroupRequest $request): JsonResponse
    {
        $g = $this->parties->createGroup($request->validated());

        return response()->json(['data' => ['id' => $g->id, 'party_type' => $g->party_type, 'name' => $g->name]], 201);
    }

    /** POST /parties/bulk-deactivate: always 200 with per-item results (BulkResult); the body only fails as a whole on 422. */
    public function bulkDeactivate(BulkIdsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->parties->bulkDeactivate(array_map('intval', $request->validated('ids')))]);
    }

    public function update(PartyRequest $request, int $id): JsonResponse
    {
        $party = $this->find($id);
        $updated = $this->parties->update($party, $request->partyData(), $this->ifMatchVersion($request));

        return $this->partyResponse($request, $updated);
    }

    public function deactivate(Request $request, int $id): JsonResponse
    {
        return $this->partyResponse($request, $this->parties->setStatus($this->find($id), 'inactive'));
    }

    public function reactivate(Request $request, int $id): JsonResponse
    {
        return $this->partyResponse($request, $this->parties->setStatus($this->find($id), 'active'));
    }

    public function destroy(int $id): Response
    {
        $this->parties->delete($this->find($id));

        return response()->noContent();
    }

    private function find(int $id): Party
    {
        return Party::findOrFail($id);
    }

    private function partyResponse(Request $request, Party $party, int $status = 200, array $warnings = []): JsonResponse
    {
        $fresh = $this->query->base()->with('bankAccounts')->whereKey($party->id)->firstOrFail();
        $body = ['data' => (new PartyResource($fresh))->resolve($request)];
        if ($warnings) {
            $body['warnings'] = $warnings;
        }

        return response()->json($body, $status)->setEtag((string) $fresh->lock_version, true);
    }

    /** BR-049: PUT/PATCH need If-Match with the current version (428 if missing). */
    private function ifMatchVersion(Request $request): int
    {
        $h = (string) $request->headers->get('If-Match', '');
        if ($h === '') {
            throw new ApiProblem('if_match_required');
        }
        if (! preg_match('/^(?:W\/)?"?(\d+)"?$/', trim($h), $m)) {
            throw new ApiProblem('validation_failed', errors: ['If-Match' => ['Send the ETag of the version you edited, e.g. W/"3"']]);
        }

        return (int) $m[1];
    }
}
