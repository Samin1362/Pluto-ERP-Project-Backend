<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Access\PermissionResolver;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\DashboardRequest;
use App\Queries\DashboardLensQuery;
use App\Queries\DashboardQuery;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;

/** /dashboard (A1) and /dashboard/lenses/{lens} (A2–A8) (FR-010, FR-011; backlog P14.1-BE-01/02). Route permission: dashboard.view. */
class DashboardController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    public function index(DashboardRequest $request, DashboardQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->summary($this->current->id(), $request->validated()['period'] ?? 'this_month', $this->can())]);
    }

    public function lens(DashboardRequest $request, DashboardLensQuery $query, string $lens): JsonResponse
    {
        return response()->json(['data' => $query->lens($this->current->id(), $lens, $request->validated()['period'] ?? 'this_month', $this->can())]);
    }

    /** @return callable(string): bool */
    private function can(): callable
    {
        $member = $this->current->membership();

        return fn (string $p) => $member !== null && $this->permissions->allows($member, $p);
    }
}
