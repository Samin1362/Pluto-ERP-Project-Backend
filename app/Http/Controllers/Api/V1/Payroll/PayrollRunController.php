<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Domain\Payroll\PayrollRunService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollRunCreateRequest;
use App\Http\Requests\Payroll\PayrollRunListRequest;
use App\Http\Requests\Payroll\PayrollRunUpdateRequest;
use App\Http\Requests\ReasonRequest;
use App\Models\PayrollRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** Salary sheets (P3, P4; PEP-521). */
class PayrollRunController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly PayrollRunService $service) {}

    public function index(PayrollRunListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $page = PayrollRun::query()->with(['journalEntry:id,voucher_number', 'approver:id,name'])
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->orderByDesc('period_month')->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 25), page: (int) ($f['page'] ?? 1));

        return response()->json(['data' => $page->getCollection()->map(fn ($r) => PayrollRunService::resource($r))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function store(PayrollRunCreateRequest $request): JsonResponse
    {
        $in = $request->validated();

        return ($in['kind'] ?? 'salary') === 'bonus'
            ? $this->one($this->service->createBonus($in), 201)
            : $this->one($this->service->create($in['month'], $in['pay_date'] ?? null, $in['notes'] ?? null), 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->one(PayrollRun::findOrFail($id));
    }

    public function update(PayrollRunUpdateRequest $request, int $id): JsonResponse
    {
        return $this->one($this->service->update(PayrollRun::findOrFail($id), $request->validated(), $this->ifMatchVersion($request)));
    }

    public function destroy(int $id): Response
    {
        $this->service->delete(PayrollRun::findOrFail($id));

        return response()->noContent();
    }

    public function approve(int $id): JsonResponse
    {
        return $this->one($this->service->approve(PayrollRun::findOrFail($id)));
    }

    public function cancel(ReasonRequest $request, int $id): JsonResponse
    {
        return $this->one($this->service->cancel(PayrollRun::findOrFail($id), (string) $request->validated('reason')));
    }

    private function one(PayrollRun $run, int $status = 200): JsonResponse
    {
        return response()->json(['data' => PayrollRunService::resource($run, true)], $status)->setEtag((string) $run->lock_version, true);
    }
}
