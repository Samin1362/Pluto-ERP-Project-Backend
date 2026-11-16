<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Domain\Payroll\StaffAdvanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\StaffAdvanceListRequest;
use App\Http\Requests\Payroll\StaffAdvanceRequest;
use App\Http\Requests\ReasonRequest;
use App\Models\StaffAdvance;
use Illuminate\Http\JsonResponse;

/** Staff advances (P5; PEP-523). */
class StaffAdvanceController extends Controller
{
    public function __construct(private readonly StaffAdvanceService $service) {}

    public function index(StaffAdvanceListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $page = StaffAdvance::query()->with(['employee:id,code,name', 'moneyAccount:id,name,account_type', 'journalEntry:id,voucher_number'])
            ->when($f['employee_id'] ?? null, fn ($q, $v) => $q->where('employee_id', $v))->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByDesc('given_on')->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 25), page: (int) ($f['page'] ?? 1));

        return response()->json(['data' => $page->getCollection()->map(fn ($a) => StaffAdvanceService::resource($a))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function store(StaffAdvanceRequest $request): JsonResponse
    {
        return response()->json(['data' => StaffAdvanceService::resource($this->service->give($request->validated()))], 201);
    }

    public function reverse(ReasonRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => StaffAdvanceService::resource($this->service->reverse(StaffAdvance::findOrFail($id), (string) $request->validated('reason')))]);
    }
}
