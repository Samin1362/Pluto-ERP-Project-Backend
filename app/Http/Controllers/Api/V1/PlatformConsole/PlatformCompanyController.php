<?php

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Platform\OwnerAccessService;
use App\Domain\Platform\PlatformCompanyService;
use App\Domain\Platform\SupportAccessService;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlatformConsole\OwnerAccessRequest;
use App\Http\Requests\PlatformConsole\PlatformCompanyCreateRequest;
use App\Http\Requests\PlatformConsole\PlatformCompanyListRequest;
use App\Http\Requests\PlatformConsole\SupportAccessRequest;
use App\Http\Requests\ReasonRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /platform/companies (K17 platform console; PEP-483). Platform admins only (middleware `platform`). */
class PlatformCompanyController extends Controller
{
    public function __construct(private readonly PlatformCompanyService $companies, private readonly SupportAccessService $support,
        private readonly OwnerAccessService $owner) {}

    public function index(PlatformCompanyListRequest $request): JsonResponse
    {
        $page = $this->companies->page($request->validated());

        return response()->json(['data' => $page->items(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function store(PlatformCompanyCreateRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->companies->create($request->validated())], 201);
    }

    public function suspend(ReasonRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->companies->setStatus($id, 'suspended', $request->validated()['reason'])]);
    }

    /** PEP-485: open the company read-only for support. */
    public function openSupport(SupportAccessRequest $request, int $id): JsonResponse
    {
        $in = $request->validated();

        return response()->json(['data' => $this->support->open($id, (int) $request->attributes->get('auth_user')->id, $in['reason'], (int) ($in['minutes'] ?? 60))], 201);
    }

    public function endSupport(Request $request, int $id): Response
    {
        $this->support->end($id, (int) $request->attributes->get('auth_user')->id);

        return response()->noContent();
    }

    /** PEP-496: join the company as an Owner (a real membership), to work in it. */
    public function joinAsOwner(OwnerAccessRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->owner->join($id, $request->attributes->get('auth_user'), $request->validated()['reason'])], 201);
    }

    public function leaveAsOwner(Request $request, int $id): Response
    {
        $this->owner->leave($id, $request->attributes->get('auth_user'));

        return response()->noContent();
    }

    public function reactivate(ReasonRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->companies->setStatus($id, 'active', $request->validated()['reason'])]);
    }
}
