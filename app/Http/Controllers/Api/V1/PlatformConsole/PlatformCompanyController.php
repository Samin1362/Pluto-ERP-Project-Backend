<?php

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Platform\PlatformCompanyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlatformConsole\PlatformCompanyCreateRequest;
use App\Http\Requests\PlatformConsole\PlatformCompanyListRequest;
use App\Http\Requests\ReasonRequest;
use Illuminate\Http\JsonResponse;

/** /platform/companies (K17 platform console; PEP-483). Platform admins only (middleware `platform`). */
class PlatformCompanyController extends Controller
{
    public function __construct(private readonly PlatformCompanyService $companies) {}

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

    public function reactivate(ReasonRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->companies->setStatus($id, 'active', $request->validated()['reason'])]);
    }
}
