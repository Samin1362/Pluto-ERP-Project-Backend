<?php

namespace App\Http\Controllers\Api\V1\Approvals;

use App\Domain\Access\PermissionResolver;
use App\Domain\Approvals\ApprovalDecisionService;
use App\Domain\Approvals\ApprovalService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Approvals\ApprovalDecisionRequest;
use App\Http\Requests\Approvals\ApprovalListRequest;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * /approvals (L3/L4/H1; backlog P7.1-BE-05). The list shows the requests the caller may decide (their permission,
 * not their own) and the ones they submitted; `status` defaults to pending. Details for the requester or a decider.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalDecisionService $decisions, private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    public function index(ApprovalListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $member = $this->current->membership();
        $mine = array_values(array_filter(['accounting.approve', 'banking.approve', 'purchases.approve'], fn ($p) => $this->permissions->allows($member, $p)));
        $page = DB::table('approval_requests')->where('company_id', $this->current->id())->where('status', $f['status'] ?? 'pending')
            ->where(fn ($w) => $w->where('requested_by', $member->user_id)->orWhereIn('required_permission', $mine))
            ->orderByDesc('requested_at')->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 25), ['id']);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($r) => ApprovalService::present((int) $r->id))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $request = DB::table('approval_requests')->where('company_id', $this->current->id())->where('id', $id)->first() ?? throw new ApiProblem('not_found');
        if (! $this->decisions->canSee($request, $this->current->membership())) {
            throw new ApiProblem('permission_denied', 'Only the requester or an approver can open this request.');
        }

        return response()->json(['data' => ApprovalService::present($id)]);
    }

    public function approve(ApprovalDecisionRequest $request, int $id): JsonResponse
    {
        $this->exists($id);
        $this->decisions->approve($id, $this->current->membership(), $request->validated()['comment'] ?? null);

        return response()->json(['data' => ApprovalService::present($id)]);
    }

    public function reject(ApprovalDecisionRequest $request, int $id): JsonResponse
    {
        $this->exists($id);
        $this->decisions->reject($id, $this->current->membership(), $request->validated()['comment'] ?? null);

        return response()->json(['data' => ApprovalService::present($id)]);
    }

    private function exists(int $id): void
    {
        if (! DB::table('approval_requests')->where('company_id', $this->current->id())->where('id', $id)->exists()) {
            throw new ApiProblem('not_found');
        }
    }
}
