<?php

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Platform\PlanService;
use App\Domain\Platform\PlatformCompanyService;
use App\Domain\Platform\SubscriptionService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\PlatformConsole\CompanyBillingRequest;
use App\Http\Requests\PlatformConsole\CompanyPlanRequest;
use App\Http\Requests\PlatformConsole\PlanRequest;
use App\Http\Requests\PlatformConsole\SubscriptionPaymentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Pluto plans in the platform console (K17; PEP-498). Platform admins only (middleware `platform`). */
class PlatformPlanController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly PlanService $plans, private readonly SubscriptionService $subscriptions,
        private readonly PlatformCompanyService $companies) {}

    public function index(): JsonResponse
    {
        $all = $this->plans->all();

        return response()->json(['data' => $all, 'meta' => ['current_page' => 1, 'per_page' => max(1, count($all)), 'total' => count($all), 'last_page' => 1]]);
    }

    public function store(PlanRequest $request): JsonResponse
    {
        $p = $this->plans->create($request->validated());

        return response()->json(['data' => $p], 201)->setEtag((string) $p['version'], true);
    }

    public function update(PlanRequest $request, int $id): JsonResponse
    {
        $p = $this->plans->update($id, $request->validated(), $this->ifMatchVersion($request));

        return response()->json(['data' => $p])->setEtag((string) $p['version'], true);
    }

    /** PEP-503: subscription terms. */
    public function billing(CompanyBillingRequest $request, int $id): JsonResponse
    {
        $this->subscriptions->setTerms($id, $request->validated());

        return response()->json(['data' => $this->companies->one($id)]);
    }

    public function payments(int $id): JsonResponse
    {
        $all = $this->subscriptions->payments($id);

        return response()->json(['data' => $all, 'meta' => ['current_page' => 1, 'per_page' => max(1, count($all)), 'total' => count($all), 'last_page' => 1]]);
    }

    public function pay(SubscriptionPaymentRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->subscriptions->record($id, $request->validated(), (int) $request->attributes->get('auth_user')->id)], 201);
    }

    public function destroy(int $id): Response
    {
        $this->plans->delete($id);

        return response()->noContent();
    }

    public function assign(CompanyPlanRequest $request, int $id): JsonResponse
    {
        $in = $request->validated();

        return response()->json(['data' => $this->plans->assign($id, (int) $in['plan_id'], $in['overrides'] ?? null)]);
    }
}
