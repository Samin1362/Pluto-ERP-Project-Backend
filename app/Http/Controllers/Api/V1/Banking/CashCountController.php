<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Domain\Banking\CashCountService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\CashCountRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Resources\CashCountResource;
use App\Models\CashCount;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /cash-counts (G2 cash count dialog; backlog P12.1-BE-03): record a count (a difference goes for approval, BR-058) and reverse one. */
class CashCountController extends Controller
{
    public function __construct(private readonly CashCountService $counts, private readonly CurrentCompany $current) {}

    public function store(CashCountRequest $request): JsonResponse
    {
        return $this->respond($request, $this->counts->record($request->validated(), $this->current->membership()), 201);
    }

    public function reverse(ReasonRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->counts->reverse(CashCount::findOrFail($id), $request->validated('reason')));
    }

    private function respond(Request $request, CashCount $c, int $status = 200): JsonResponse
    {
        $c->loadMissing(['denominations', 'counter', 'entry', 'account']);

        return response()->json(['data' => (new CashCountResource($c))->resolve($request)], $status);
    }
}
