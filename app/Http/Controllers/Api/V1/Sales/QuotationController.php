<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Access\PermissionResolver;
use App\Domain\Sales\QuotationMailer;
use App\Domain\Sales\QuotationPdf;
use App\Domain\Sales\QuotationService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\EmailRequest;
use App\Http\Requests\Sales\QuotationListRequest;
use App\Http\Requests\Sales\QuotationRequest;
use App\Http\Requests\Sales\QuotationStatusRequest;
use App\Http\Resources\QuotationResource;
use App\Http\Resources\SalesInvoiceResource;
use App\Models\Quotation;
use App\Queries\QuotationQuery;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /quotations (E10, E13; backlog P10.1-BE-01): drafts, the list, send, status, convert to an invoice and the PDF. */
class QuotationController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly QuotationService $quotations) {}

    /** GET /quotations (E13): headers only; lines on the detail. */
    public function index(QuotationListRequest $request, QuotationQuery $query): JsonResponse
    {
        $page = $query->page($request->validated());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Quotation $q) => (new QuotationResource($q, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(QuotationRequest $request): JsonResponse
    {
        return $this->respond($request, $this->quotations->create($request->validated()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, Quotation::findOrFail($id));
    }

    public function update(QuotationRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->quotations->update(Quotation::findOrFail($id), $request->validated(), $version));
    }

    public function destroy(int $id): Response
    {
        $this->quotations->delete(Quotation::findOrFail($id));

        return response()->noContent();
    }

    /** POST /quotations/{id}/send: marks it sent and queues the e-mail with the PDF. */
    public function send(EmailRequest $request, int $id, QuotationMailer $mailer): JsonResponse
    {
        $f = $request->validated();

        return $this->respond($request, $mailer->send(Quotation::findOrFail($id), $f['to'], $f['cc'] ?? [], $f['message'] ?? null));
    }

    public function status(QuotationStatusRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->quotations->setStatus(Quotation::findOrFail($id), $request->validated('status'), $request->validated('reason')));
    }

    /** POST /quotations/{id}/convert: the new draft invoice (201), which the UI opens in E6. */
    public function convert(Request $request, int $id, CurrentCompany $current, PermissionResolver $permissions): JsonResponse
    {
        $invoice = $this->quotations->convert(Quotation::findOrFail($id))->loadMissing(['lines', 'party', 'createdBy']);
        $costs = $permissions->allows($current->membership(), 'inventory.view_costs');

        return response()->json(['data' => (new SalesInvoiceResource($invoice, $costs))->resolve($request)], 201)->setEtag((string) $invoice->lock_version, true);
    }

    public function pdf(int $id, QuotationPdf $pdf): Response
    {
        $q = Quotation::findOrFail($id);

        return response($pdf->render($q), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$pdf->filename($q).'"']);
    }

    private function respond(Request $request, Quotation $q, int $status = 200): JsonResponse
    {
        $q->loadMissing(['lines', 'party', 'createdBy', 'invoice:id,quotation_id,number']);

        return response()->json(['data' => (new QuotationResource($q))->resolve($request)], $status)->setEtag((string) $q->lock_version, true);
    }
}
