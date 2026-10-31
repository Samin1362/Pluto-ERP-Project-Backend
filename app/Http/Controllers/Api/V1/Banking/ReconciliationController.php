<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Domain\Banking\ReconciliationService;
use App\Domain\Banking\StatementImportService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\ReconciliationRequest;
use App\Http\Requests\Banking\StatementImportRequest;
use App\Http\Resources\ReconciliationResource;
use App\Models\BankReconciliation;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Statement import and the reconciliation workspace (G10; backlog P12.1-BE-04/05; route permission banking.reconcile). */
class ReconciliationController extends Controller
{
    public function __construct(private readonly ReconciliationService $recs, private readonly CurrentCompany $current) {}

    public function import(StatementImportRequest $request, StatementImportService $imports): JsonResponse
    {
        $in = $request->validated();

        return response()->json(['data' => $imports->import($request->file('file'), (int) $in['money_account_id'], $in['mapping'] ?? null)], 201);
    }

    public function start(ReconciliationRequest $request): JsonResponse
    {
        return $this->respond($request, $this->recs->start($request->validated()));
    }

    public function show(ReconciliationRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, BankReconciliation::findOrFail($id), $request->validated('filter') ?? 'all');
    }

    public function match(ReconciliationRequest $request, int $id, int $lineId): JsonResponse
    {
        return $this->respond($request, $this->recs->match(BankReconciliation::findOrFail($id), $lineId, (int) $request->validated('journal_line_id')));
    }

    public function createEntry(ReconciliationRequest $request, int $id, int $lineId): JsonResponse
    {
        return $this->respond($request, $this->recs->createEntry(BankReconciliation::findOrFail($id), $lineId, $request->validated(), $this->current->membership()));
    }

    public function pair(ReconciliationRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->recs->pair(BankReconciliation::findOrFail($id), $request->validated('line_ids')));
    }

    public function undo(Request $request, int $id, int $lineId): JsonResponse
    {
        return $this->respond($request, $this->recs->undo(BankReconciliation::findOrFail($id), $lineId));
    }

    public function acceptSuggestions(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->recs->acceptSuggestions(BankReconciliation::findOrFail($id)));
    }

    public function finish(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->recs->finish(BankReconciliation::findOrFail($id)));
    }

    private function respond(Request $request, BankReconciliation $r, string $filter = 'all'): JsonResponse
    {
        $r->loadMissing('account');

        return response()->json(['data' => (new ReconciliationResource($r, $filter))->resolve($request)])->setEtag((string) $r->lock_version, true);
    }
}
