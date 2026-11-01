<?php

namespace App\Http\Controllers\Api\V1\Lc;

use App\Domain\Access\PermissionResolver;
use App\Domain\Lc\LcPostingService;
use App\Domain\Lc\LcService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lc\ChargePaymentRequest;
use App\Http\Requests\Lc\LandedCostRequest;
use App\Http\Requests\Lc\LcAmendmentListRequest;
use App\Http\Requests\Lc\LcAmendmentRequest;
use App\Http\Requests\Lc\LcAmendmentStatusRequest;
use App\Http\Requests\Lc\LcChargeListRequest;
use App\Http\Requests\Lc\LcChargeRequest;
use App\Http\Requests\Lc\LcCloseRequest;
use App\Http\Requests\Lc\LcDocumentRequest;
use App\Http\Requests\Lc\LcListRequest;
use App\Http\Requests\Lc\LcPaymentRequest;
use App\Http\Requests\Lc\LcReportRequest;
use App\Http\Requests\Lc\LcRequest;
use App\Http\Requests\Lc\LcShipmentRequest;
use App\Http\Requests\Lc\LcTemplateRequest;
use App\Http\Requests\Lc\LcTransitionRequest;
use App\Http\Requests\ReasonRequest;
use App\Models\LandedCostAllocation;
use App\Models\LcAmendment;
use App\Models\LcCharge;
use App\Models\LcDocument;
use App\Models\LcPayment;
use App\Models\LetterOfCredit;
use App\Queries\LcQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use App\Support\Reports\ReportExporter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Letters of credit and landed cost (M1–M8, LC dialogs; FR-090–FR-099; backlog P16.1-BE-01..09). Route permissions
 * lc.view / lc.create / lc.edit / lc.delete; services check the status machine and the money rules.
 */
class LcController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly LcService $lcs, private readonly LcPostingService $postings, private readonly LcQuery $query, private readonly CurrentCompany $current) {}

    private function page($p, callable $map): JsonResponse
    {
        return response()->json(['data' => $p->getCollection()->map($map)->values()->all(),
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()]]);
    }

    private function all(array $rows): JsonResponse
    {
        return response()->json(['data' => $rows, 'meta' => ['current_page' => 1, 'per_page' => max(count($rows), 1), 'total' => count($rows), 'last_page' => 1]]);
    }

    private function lc(LetterOfCredit $lc, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $this->lcs->resource($lc->refresh())], $status)->setEtag((string) $lc->lock_version, true);
    }

    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->query->overview($this->current->id())]);
    }

    public function index(LcListRequest $request): JsonResponse
    {
        $f = $request->validated();

        return $this->page($this->query->list($f)->paginate((int) ($f['per_page'] ?? 25)), fn (LetterOfCredit $lc) => $this->lcs->resource($lc, false));
    }

    public function store(LcRequest $request): JsonResponse
    {
        $lc = $this->lcs->create($request->validated());

        return $this->lc($lc, 201)->header('Location', url("/api/v1/letters-of-credit/{$lc->id}"));
    }

    public function show(int $id): JsonResponse
    {
        return $this->lc(LetterOfCredit::findOrFail($id));
    }

    public function update(LcRequest $request, int $id): JsonResponse
    {
        return $this->lc($this->lcs->update($id, $request->validated(), $this->ifMatchVersion($request)));
    }

    public function destroy(int $id): Response
    {
        $this->lcs->delete($id);

        return response()->noContent();
    }

    public function transition(LcTransitionRequest $request, int $id, PermissionResolver $permissions): JsonResponse
    {
        if ($request->validated()['action'] === 'cancel' && ! $permissions->allows($this->current->membership(), 'lc.delete') && LetterOfCredit::whereKey($id)->value('status') !== 'draft') {
            throw new ApiProblem('permission_denied', 'Cancelling an LC at the bank needs lc.delete.');
        }

        return $this->lc($this->lcs->transition($id, $request->validated(), $this->ifMatchVersion($request)));
    }

    public function close(LcCloseRequest $request, int $id): JsonResponse
    {
        return $this->lc($this->postings->close($id, $request->validated()));
    }

    public function pdf(int $id): HttpResponse
    {
        $lc = LetterOfCredit::findOrFail($id);
        $data = $this->lcs->resource($lc);
        $pdf = Pdf::loadView('pdf.letter-of-credit', ['lc' => $data, 'company' => $this->current->company()])->setPaper('a4')->setOption('isFontSubsettingEnabled', true)->output();

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"{$lc->lc_number}.pdf\""]);
    }

    // ---------------------------------------------------------------- documents, templates

    public function documents(int $id): JsonResponse
    {
        LetterOfCredit::findOrFail($id);

        return $this->all(LcDocument::where('letter_of_credit_id', $id)->orderBy('id')->get()->map(fn ($d) => LcService::document($d))->all());
    }

    public function addDocument(LcDocumentRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => LcService::document($this->lcs->addDocument($id, $request->validated()))], 201);
    }

    public function updateDocument(LcDocumentRequest $request, int $id): JsonResponse
    {
        $d = $this->lcs->updateDocument($id, $request->validated(), $this->ifMatchVersion($request));

        return response()->json(['data' => LcService::document($d)])->setEtag((string) $d->lock_version, true);
    }

    public function matrix(): JsonResponse
    {
        return response()->json(['data' => $this->query->matrix($this->current->id())]);
    }

    public function templates(): JsonResponse
    {
        return $this->all($this->lcs->templates());
    }

    public function storeTemplate(LcTemplateRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->lcs->saveTemplate($request->validated())], 201);
    }

    public function updateTemplate(LcTemplateRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->lcs->saveTemplate($request->validated(), $id)]);
    }

    // ---------------------------------------------------------------- amendments, shipments

    public function amendments(LcAmendmentListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = LcAmendment::query()->when($f['status'] ?? null, fn ($q, $s) => $s === 'pending' ? $q->whereIn('status', ['requested', 'issued']) : $q->where('status', $s))
            ->when(($f['q'] ?? '') !== '', fn ($q) => $q->whereIn('letter_of_credit_id', LetterOfCredit::where('lc_number', 'like', '%'.addcslashes($f['q'], '%_\\').'%')->select('id')))
            ->orderBy('requested_on', ($f['sort'] ?? '-date') === 'date' ? 'asc' : 'desc')->orderByDesc('id');

        return $this->page($q->paginate((int) ($f['per_page'] ?? 25)), fn ($a) => LcService::amendment($a));
    }

    public function amend(LcAmendmentRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => LcService::amendment($this->lcs->amend($id, $request->validated(), $this->postings))], 201);
    }

    public function updateAmendment(LcAmendmentStatusRequest $request, int $id): JsonResponse
    {
        $a = $this->lcs->updateAmendment($id, $request->validated(), $this->ifMatchVersion($request));

        return response()->json(['data' => LcService::amendment($a)])->setEtag((string) $a->lock_version, true);
    }

    public function addShipment(LcShipmentRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => LcService::shipment($this->lcs->addShipment($id, $request->validated()))], 201);
    }

    public function updateShipment(LcShipmentRequest $request, int $id): JsonResponse
    {
        $s = $this->lcs->updateShipment($id, $request->validated(), $this->ifMatchVersion($request));

        return response()->json(['data' => LcService::shipment($s)])->setEtag((string) $s->lock_version, true);
    }

    // ---------------------------------------------------------------- charges, payments

    public function charges(LcChargeListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = LcCharge::with('letterOfCredit')->where('posting_status', 'posted')
            ->when($f['letter_of_credit_id'] ?? null, fn ($q, $v) => $q->where('letter_of_credit_id', $v))->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('charge_date', ($f['sort'] ?? '-date') === 'date' ? 'asc' : 'desc')->orderByDesc('id');

        return $this->page($q->paginate((int) ($f['per_page'] ?? 25)), fn (LcCharge $c) => LcPostingService::charge($c, $c->letterOfCredit->lc_number));
    }

    public function addCharge(LcChargeRequest $request): JsonResponse
    {
        $c = $this->postings->addCharge($request->validated());

        return response()->json(['data' => LcPostingService::charge($c, $c->letterOfCredit->lc_number)], 201);
    }

    public function payCharge(ChargePaymentRequest $request, int $id): JsonResponse
    {
        $c = $this->postings->payCharge($id, $request->validated());

        return response()->json(['data' => LcPostingService::charge($c, $c->letterOfCredit->lc_number)]);
    }

    public function reverseCharge(ReasonRequest $request, int $id): JsonResponse
    {
        $c = $this->postings->reverseCharge($id, $request->validated()['reason']);

        return response()->json(['data' => LcPostingService::charge($c, $c->letterOfCredit->lc_number)]);
    }

    public function payments(int $id): JsonResponse
    {
        LetterOfCredit::findOrFail($id);

        return $this->all(LcPayment::where('letter_of_credit_id', $id)->orderBy('payment_date')->orderBy('id')->get()->map(fn ($p) => LcPostingService::payment($p))->all());
    }

    public function pay(LcPaymentRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => LcPostingService::payment($this->postings->pay($id, $request->validated()))], 201);
    }

    public function reversePayment(ReasonRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => LcPostingService::payment($this->postings->reversePayment($id, $request->validated()['reason']))]);
    }

    // ---------------------------------------------------------------- landed cost, cost, reports

    public function landedCostPreview(LandedCostRequest $request, int $id): JsonResponse
    {
        $p = $this->postings->landedCost($id, $request->validated());

        return response()->json(['data' => array_filter($p, fn ($k) => ! str_starts_with($k, '_'), ARRAY_FILTER_USE_KEY)]);
    }

    public function landedCost(LandedCostRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => LcPostingService::allocation($this->postings->allocate($id, $request->validated()))], 201);
    }

    public function reverseAllocation(ReasonRequest $request, int $id): JsonResponse
    {
        LandedCostAllocation::findOrFail($id);

        return response()->json(['data' => LcPostingService::allocation($this->postings->reverseAllocation($id, $request->validated()['reason']))]);
    }

    public function costSummary(int $id): JsonResponse
    {
        return response()->json(['data' => $this->query->costSummary(LetterOfCredit::findOrFail($id))]);
    }

    public function report(LcReportRequest $request, string $report, ReportExporter $exporter, PermissionResolver $permissions): HttpResponse
    {
        $f = $request->validated();
        $data = $this->query->report($this->current->id(), $report, $f);
        if (isset($f['format'])) {
            if (! $permissions->allows($this->current->membership(), 'reports.export')) {
                throw new ApiProblem('permission_denied', 'Exporting needs reports.export.');
            }

            return $exporter->download($data, $f['format'], "lc-{$report}");
        }

        return response()->json(['data' => $data]);
    }
}
