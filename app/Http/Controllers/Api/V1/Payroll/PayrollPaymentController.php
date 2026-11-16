<?php

namespace App\Http\Controllers\Api\V1\Payroll;

use App\Domain\Payroll\PayrollPaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payroll\PayrollPaymentRequest;
use App\Http\Requests\ReasonRequest;
use App\Models\PayrollPayment;
use App\Models\PayrollRun;
use Illuminate\Http\JsonResponse;

/** Salary payments (P4; PEP-522). */
class PayrollPaymentController extends Controller
{
    public function __construct(private readonly PayrollPaymentService $service) {}

    public function index(int $id): JsonResponse
    {
        $run = PayrollRun::findOrFail($id);
        $rows = PayrollPayment::where('payroll_run_id', $run->id)->orderByDesc('paid_on')->orderByDesc('id')->get();

        return response()->json(['data' => $rows->map(fn ($p) => PayrollPaymentService::resource($p))->all(),
            'meta' => ['current_page' => 1, 'per_page' => max(1, $rows->count()), 'total' => $rows->count(), 'last_page' => 1]]);
    }

    public function store(PayrollPaymentRequest $request, int $id): JsonResponse
    {
        $p = $this->service->pay(PayrollRun::findOrFail($id), $request->validated());

        return response()->json(['data' => PayrollPaymentService::resource($p)], 201);
    }

    public function reverse(ReasonRequest $request, int $id): JsonResponse
    {
        $p = $this->service->reverse(PayrollPayment::findOrFail($id), (string) $request->validated('reason'));

        return response()->json(['data' => PayrollPaymentService::resource($p)]);
    }
}
