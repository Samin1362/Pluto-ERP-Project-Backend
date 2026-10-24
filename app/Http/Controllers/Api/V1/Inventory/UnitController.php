<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Domain\Inventory\CatalogueService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\LookupListRequest;
use App\Http\Requests\Inventory\UnitRequest;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;

/** GET /units and POST /units (backlog P3.1-BE-01). Units are seeded per company and added inline in D2; no edit or delete. */
class UnitController extends Controller
{
    public function __construct(private readonly CatalogueService $catalogue) {}

    public function index(LookupListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = Unit::query();
        if (($f['q'] ?? '') !== '') {
            $q->where('name', 'like', '%'.addcslashes($f['q'], '%_\\').'%');
        }
        $sort = $f['sort'] ?? 'name';
        $page = $q->orderBy('name', str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id')->paginate((int) ($f['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Unit $u) => self::lookup($u))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(UnitRequest $request): JsonResponse
    {
        return response()->json(['data' => self::lookup($this->catalogue->createUnit($request->validated()))], 201);
    }

    /** Lookup schema (05): units carry only id and name. */
    private static function lookup(Unit $u): array
    {
        return ['id' => $u->id, 'name' => $u->name];
    }
}
