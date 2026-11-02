<?php

namespace App\Http\Controllers\Api\V1\Lc;

use App\Domain\Lc\FxRevaluationService;
use App\Domain\Lc\LcFinanceService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lc\BankFacilityRequest;
use App\Http\Requests\Lc\FxRevaluationListRequest;
use App\Http\Requests\Lc\FxRevaluationRequest;
use App\Http\Requests\Lc\LcLoanConvertRequest;
use App\Http\Requests\Lc\LcLoanInterestRequest;
use App\Http\Requests\Lc\LcLoanListRequest;
use App\Http\Requests\Lc\LcLoanRepaymentRequest;
use App\Http\Requests\ReasonRequest;
use App\Models\BankFacility;
use App\Models\FxRevaluation;
use App\Models\LcLoan;
use App\Models\LcLoanRepayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * LC finance inside the LC portal (P16 LC finance; 06 §12.11–12.13; BR-066–BR-068): bank facilities and limits,
 * PAD/LTR loans and their repayments, period-end FX revaluation. Route permissions lc.* (revaluation accounting.*).
 */
class LcFinanceController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly LcFinanceService $finance, private readonly FxRevaluationService $fx) {}

    private function page($p, callable $map): JsonResponse
    {
        return response()->json(['data' => $p->getCollection()->map($map)->values()->all(),
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()]]);
    }

    // ---------------------------------------------------------------- bank facilities

    public function facilities(): JsonResponse
    {
        $rows = BankFacility::orderBy('id')->get()->map(fn ($f) => $this->finance->facilityResource($f))->all();

        return response()->json(['data' => $rows, 'meta' => ['current_page' => 1, 'per_page' => max(count($rows), 1), 'total' => count($rows), 'last_page' => 1]]);
    }

    public function storeFacility(BankFacilityRequest $request): JsonResponse
    {
        $f = $this->finance->saveFacility($request->validated());

        return response()->json(['data' => $this->finance->facilityResource($f)], 201)->setEtag((string) $f->lock_version, true);
    }

    public function updateFacility(BankFacilityRequest $request, int $id): JsonResponse
    {
        BankFacility::findOrFail($id);
        $f = $this->finance->saveFacility($request->validated(), $id, $this->ifMatchVersion($request));

        return response()->json(['data' => $this->finance->facilityResource($f)])->setEtag((string) $f->lock_version, true);
    }

    // ---------------------------------------------------------------- loans

    public function loans(LcLoanListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $sort = $f['sort'] ?? 'due_date';
        $q = LcLoan::query()->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($f['bank_account_id'] ?? null, fn ($q, $v) => $q->where('money_account_id', $v))
            ->when($f['letter_of_credit_id'] ?? null, fn ($q, $v) => $q->where('letter_of_credit_id', $v))
            ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('loan_number', 'like', '%'.addcslashes($v, '%_').'%')
                ->orWhereIn('letter_of_credit_id', DB::table('letters_of_credit')->where('lc_number', 'like', '%'.addcslashes($v, '%_').'%')->select('id'))))
            ->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id');

        return $this->page($q->paginate((int) ($f['per_page'] ?? 25)), fn ($l) => $this->finance->loanResource($l, false));
    }

    private function loan(LcLoan $l, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->finance->loanResource($l->refresh())], $status)->setEtag((string) $l->lock_version, true);
    }

    public function showLoan(int $id): JsonResponse
    {
        return $this->loan(LcLoan::findOrFail($id));
    }

    public function interest(LcLoanInterestRequest $request, int $id): JsonResponse
    {
        $loan = LcLoan::findOrFail($id);

        return response()->json(['data' => $this->finance->quote($loan, $request->validated()['as_of'] ?? CarbonImmutable::now('Asia/Dhaka')->toDateString())]);
    }

    public function repay(LcLoanRepaymentRequest $request, int $id): JsonResponse
    {
        LcLoan::findOrFail($id);

        return $this->loan($this->finance->repay($id, $request->validated()), 201);
    }

    public function convert(LcLoanConvertRequest $request, int $id): JsonResponse
    {
        LcLoan::findOrFail($id);

        return $this->loan($this->finance->convert($id, $request->validated(), $this->ifMatchVersion($request)));
    }

    public function reverseRepayment(ReasonRequest $request, int $id): JsonResponse
    {
        LcLoanRepayment::findOrFail($id);

        return $this->loan($this->finance->reverseRepayment($id, $request->validated()['reason']));
    }

    // ---------------------------------------------------------------- FX revaluation

    public function revaluations(FxRevaluationListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = FxRevaluation::query()->when($f['q'] ?? null, fn ($q, $v) => $q->where('note', 'like', '%'.addcslashes($v, '%_').'%'))
            ->orderBy('revaluation_date', ($f['sort'] ?? '-date') === 'date' ? 'asc' : 'desc')->orderByDesc('id');

        return $this->page($q->paginate((int) ($f['per_page'] ?? 25)), fn ($r) => $this->fx->resource($r));
    }

    public function previewRevaluation(FxRevaluationRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->fx->preview($request->validated())]);
    }

    public function revalue(FxRevaluationRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->fx->resource($this->fx->post($request->validated()))], 201);
    }

    public function reverseRevaluation(ReasonRequest $request, int $id): JsonResponse
    {
        FxRevaluation::findOrFail($id);

        return response()->json(['data' => $this->fx->resource($this->fx->reverse($id, $request->validated()['reason']))]);
    }
}
