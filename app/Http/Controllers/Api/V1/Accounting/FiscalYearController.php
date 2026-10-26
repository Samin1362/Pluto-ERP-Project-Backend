<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Domain\Accounting\FiscalYearService;
use App\Domain\Accounting\PeriodService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\PeriodLockRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Resources\FiscalYearResource;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Period lock/unlock and year close (K4; backlog P7.1-BE-07). GET /fiscal-years stays in PlatformLookupController. */
class FiscalYearController extends Controller
{
    public function __construct(private readonly PeriodService $periods, private readonly FiscalYearService $years) {}

    public function lock(PeriodLockRequest $request, int $id): JsonResponse
    {
        return $this->period($this->periods->lock(AccountingPeriod::findOrFail($id), $request->validated()['note'] ?? null));
    }

    public function unlock(ReasonRequest $request, int $id): JsonResponse
    {
        return $this->period($this->periods->unlock(AccountingPeriod::findOrFail($id), $request->validated()['reason']));
    }

    public function close(Request $request, int $id): JsonResponse
    {
        $year = $this->years->close(FiscalYear::findOrFail($id));

        return response()->json(['data' => (new FiscalYearResource($year))->resolve($request)]);
    }

    /** AccountingPeriod (05 schema). */
    private function period(AccountingPeriod $p): JsonResponse
    {
        $p->loadMissing('lockedBy');

        return response()->json(['data' => ['id' => $p->id, 'name' => $p->name, 'start_date' => $p->start_date->toDateString(), 'end_date' => $p->end_date->toDateString(),
            'status' => $p->status, 'locked_at' => $p->locked_at?->toIso8601String(), 'locked_by' => $p->lockedBy ? ['id' => $p->lockedBy->id, 'name' => $p->lockedBy->name] : null,
            'lock_note' => $p->lock_note]]);
    }
}
