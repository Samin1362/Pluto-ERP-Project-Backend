<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Access\RoleService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\RoleRequest;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** /roles and /permissions (K11; FR-007; backlog P15.1-BE-06). Route permission: settings.roles. */
class RoleController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly RoleService $roles) {}

    public function index(): JsonResponse
    {
        $rows = Role::orderByDesc('is_system')->orderBy('id')->get()->map(fn (Role $r) => $this->roles->resource($r))->all();

        return response()->json(['data' => $rows, 'meta' => ['current_page' => 1, 'per_page' => max(count($rows), 1), 'total' => count($rows), 'last_page' => 1]]);
    }

    public function show(int $id): JsonResponse
    {
        $r = Role::findOrFail($id);

        return response()->json(['data' => $this->roles->resource($r)])->setEtag((string) $r->lock_version, true);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $r = $this->roles->create($request->validated());

        return response()->json(['data' => $this->roles->resource($r)], 201)->setEtag((string) $r->lock_version, true)->header('Location', url("/api/v1/roles/{$r->id}"));
    }

    public function update(RoleRequest $request, int $id): JsonResponse
    {
        $r = $this->roles->replace($id, $request->validated(), $this->ifMatchVersion($request));

        return response()->json(['data' => $this->roles->resource($r)])->setEtag((string) $r->lock_version, true);
    }

    public function destroy(int $id): Response
    {
        $this->roles->delete($id);

        return response()->noContent();
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => $this->roles->catalogue()]);
    }
}
