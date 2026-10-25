<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Access\PermissionResolver;
use App\Domain\Sales\InvoicePdf;
use App\Domain\Sales\SalesInvoiceCancellationService;
use App\Domain\Sales\SalesInvoiceMailer;
use App\Domain\Sales\SalesInvoicePostingService;
use App\Domain\Sales\SalesInvoicePreparer;
use App\Domain\Sales\SalesInvoiceService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelRequest;
use App\Http\Requests\Parties\BulkIdsRequest;
use App\Http\Requests\PostRequest;
use App\Http\Requests\Sales\DeliveryRequest;
use App\Http\Requests\Sales\EmailRequest;
use App\Http\Requests\Sales\InvoicePdfRequest;
use App\Http\Requests\Sales\SalesInvoiceListRequest;
use App\Http\Requests\Sales\SalesInvoiceRequest;
use App\Http\Resources\SalesInvoiceResource;
use App\Models\SalesInvoice;
use App\Queries\SalesInvoiceQuery;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /sales-invoices (E2–E6; backlog P5.1): calculate, drafts, the list, posting (one or bulk), cancelling and delivery details. */
class SalesInvoiceController extends Controller
{
    use ReadsIfMatch;

    public function __construct(
        private readonly SalesInvoicePreparer $preparer,
        private readonly SalesInvoiceService $invoices,
        private readonly CurrentCompany $current,
        private readonly PermissionResolver $permissions,
    ) {}

    /** DocumentCalculation (05): authoritative totals for an unsaved invoice; the UI renders only these (DEC-09). */
    public function calculate(SalesInvoiceRequest $request): JsonResponse
    {
        $inv = $this->preparer->prepare($request->validated());
        $c = $inv->calculation;

        return response()->json(['data' => [
            'lines' => array_map(fn ($l, $i) => [
                'line_no' => $l->lineNo, 'gross' => (string) $l->gross, 'discount_amount' => (string) $l->discountTotal(), 'tax_amount' => (string) $l->tax,
                'line_total' => (string) $l->total, 'available_qty' => $inv->lines[$i]['available'],
            ], $c->lines, array_keys($c->lines)),
            'warnings' => $inv->warnings,
            'party_balance_before' => (string) $inv->partyBalanceBefore,
            'party_balance_after' => (string) $inv->partyBalanceAfter(),
            'credit_limit' => $inv->party->credit_limit,
            'effects' => $inv->effects,
            'subtotal' => (string) $c->subtotal,
            'discount_total' => (string) $c->discountTotal,
            'tax_total' => (string) $c->taxTotal,
            'rounding_adjustment' => (string) $c->rounding,
            'total' => (string) $c->total,
            'amount_in_words' => $c->amountInWords,
        ]]);
    }

    /** GET /sales-invoices (E2): invoice headers; lines and journal only on the detail (GET /sales-invoices/{id}). */
    public function index(SalesInvoiceListRequest $request, SalesInvoiceQuery $query): JsonResponse
    {
        $page = $query->page($request->validated());

        return response()->json([
            'data' => $page->getCollection()->map(fn (SalesInvoice $i) => (new SalesInvoiceResource($i, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(SalesInvoiceRequest $request): JsonResponse
    {
        return $this->respond($request, $this->invoices->createDraft($request->validated()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, SalesInvoice::with('lines')->findOrFail($id));
    }

    public function update(SalesInvoiceRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->invoices->updateDraft(SalesInvoice::findOrFail($id), $request->validated(), $version));
    }

    /** POST /sales-invoices/{id}/post (P5.1-BE-03): stock out at average cost, journal, customer balance. */
    public function post(PostRequest $request, int $id, SalesInvoicePostingService $posting): JsonResponse
    {
        return $this->respond($request, $posting->post(SalesInvoice::findOrFail($id), (bool) $request->validated('confirm_backdated', false), $request->validated('receipt')));
    }

    /** POST /sales-invoices/bulk-post (P5.1-BE-04): each draft in its own transaction; per-item results (BulkResult). */
    public function bulkPost(BulkIdsRequest $request, SalesInvoicePostingService $posting): JsonResponse
    {
        return response()->json(['data' => $posting->bulkPost(array_map('intval', $request->validated('ids')))]);
    }

    /** POST /sales-invoices/{id}/cancel (P5.1-BE-04): reversing entry, stock back at the cost it left at. */
    public function cancel(CancelRequest $request, int $id, SalesInvoiceCancellationService $cancellation): JsonResponse
    {
        $f = $request->validated();

        return $this->respond($request, $cancellation->cancel(SalesInvoice::findOrFail($id), $f['reason'], $f['note'] ?? null, $f['cancellation_date'] ?? null));
    }

    /** PATCH /sales-invoices/{id}/delivery (GAP-12): challan, vehicle, delivery time, also after posting. */
    public function delivery(DeliveryRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->invoices->updateDelivery(SalesInvoice::findOrFail($id), $request->validated(), $version));
    }

    /**
     * GET /sales-invoices/{id}/pdf (E4 print, P5.1-BE-05): the A4 invoice per the company's invoice design (K7), or with
     * ?variant=challan the delivery challan without prices (GAP-12). Drafts and cancelled invoices carry a stamp.
     */
    public function pdf(InvoicePdfRequest $request, int $id, InvoicePdf $pdf): Response
    {
        $invoice = SalesInvoice::findOrFail($id);
        $variant = $request->validated('variant', 'invoice');

        return response($pdf->render($invoice, $variant), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($invoice, $variant).'"']);
    }

    /** POST /sales-invoices/{id}/send (P5.1-BE-06): queues the e-mail with the PDF attached; 202. */
    public function send(EmailRequest $request, int $id, SalesInvoiceMailer $mailer): Response
    {
        $f = $request->validated();
        $mailer->send(SalesInvoice::findOrFail($id), $f['to'], $f['cc'] ?? [], $f['message'] ?? null);

        return response()->noContent(202);
    }

    public function destroy(int $id): Response
    {
        $this->invoices->deleteDraft(SalesInvoice::findOrFail($id));

        return response()->noContent();
    }

    private function respond(Request $request, SalesInvoice $invoice, int $status = 200): JsonResponse
    {
        $invoice->loadMissing(['lines', 'party', 'createdBy']);
        $costs = $this->permissions->allows($this->current->membership(), 'inventory.view_costs');

        return response()->json(['data' => (new SalesInvoiceResource($invoice, $costs))->resolve($request)], $status)->setEtag((string) $invoice->lock_version, true);
    }
}
