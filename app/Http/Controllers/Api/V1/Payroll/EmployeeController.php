<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Domain\Payroll\EmployeeService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\EmployeeListRequest;
use App\Http\Requests\Payroll\EmployeeRequest;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;

/** Employees (P1, P2; PEP-520). Payroll is a plan module (module:payroll) and private (payroll.* permissions). */
class EmployeeController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly EmployeeService $service) {}

    public function index(EmployeeListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $sort = $f['sort'] ?? 'code';
        $status = $f['status'] ?? 'active';
        $page = Employee::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($f['department'] ?? null, fn ($q, $v) => $q->where('department', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'like', "%{$v}%")->orWhere('code', 'like', "%{$v}%")
                ->orWhere('mobile', 'like', "%{$v}%")->orWhere('designation', 'like', "%{$v}%")))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 25), page: (int) ($f['page'] ?? 1));

        return response()->json(['data' => $page->getCollection()->map(fn ($e) => EmployeeService::resource($e))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function store(EmployeeRequest $request): JsonResponse
    {
        $e = $this->service->create($request->validated());

        return response()->json(['data' => EmployeeService::resource($e)], 201)->setEtag((string) $e->lock_version, true);
    }

    public function show(int $id): JsonResponse
    {
        $e = Employee::findOrFail($id);

        return response()->json(['data' => EmployeeService::resource($e)])->setEtag((string) $e->lock_version, true);
    }

    public function update(EmployeeRequest $request, int $id): JsonResponse
    {
        $e = $this->service->update(Employee::findOrFail($id), $request->validated(), $this->ifMatchVersion($request));

        return response()->json(['data' => EmployeeService::resource($e)])->setEtag((string) $e->lock_version, true);
    }
}
