<?php

namespace App\Http\Controllers\Api\V1\Purchases;

use App\Domain\Purchases\PurchaseOrderMailer;
use App\Domain\Purchases\PurchaseOrderPdf;
use App\Domain\Purchases\PurchaseOrderService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\LowStockOrderRequest;
use App\Http\Requests\Purchases\PurchaseOrderListRequest;
use App\Http\Requests\Purchases\PurchaseOrderRequest;
use App\Http\Requests\Purchases\PurchaseOrderStatusRequest;
use App\Http\Requests\Sales\EmailRequest;
use App\Http\Resources\PurchaseBillResource;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Queries\PurchaseOrderQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /purchase-orders (F8, F11, D12; backlog P10.2-BE-01): drafts, the list, send, close/cancel, convert to a bill, from low stock, PDF. */
class PurchaseOrderController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly PurchaseOrderService $orders) {}

    /** GET /purchase-orders (F11): headers only; lines and bills on the detail. */
    public function index(PurchaseOrderListRequest $request, PurchaseOrderQuery $query): JsonResponse
    {
        $page = $query->page($request->validated());

        return response()->json([
            'data' => $page->getCollection()->map(fn (PurchaseOrder $po) => (new PurchaseOrderResource($po, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(PurchaseOrderRequest $request): JsonResponse
    {
        return $this->respond($request, $this->orders->create($request->validated()), 201);
    }

    /** POST /purchase-orders/from-low-stock (D12): BulkResult with the created PO ids and the products that could not be ordered. */
    public function fromLowStock(LowStockOrderRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->orders->fromLowStock($request->validated('rows'), $request->validated('order_date'))], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, PurchaseOrder::findOrFail($id));
    }

    public function update(PurchaseOrderRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->orders->update(PurchaseOrder::findOrFail($id), $request->validated(), $version));
    }

    public function destroy(int $id): Response
    {
        $this->orders->delete(PurchaseOrder::findOrFail($id));

        return response()->noContent();
    }

    public function send(EmailRequest $request, int $id, PurchaseOrderMailer $mailer): JsonResponse
    {
        $f = $request->validated();

        return $this->respond($request, $mailer->send(PurchaseOrder::findOrFail($id), $f['to'], $f['cc'] ?? [], $f['message'] ?? null));
    }

    public function status(PurchaseOrderStatusRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->orders->setStatus(PurchaseOrder::findOrFail($id), $request->validated('status'), $request->validated('reason')));
    }

    /** POST /purchase-orders/{id}/convert: the new draft bill (201), which the UI opens in F3. */
    public function convert(Request $request, int $id): JsonResponse
    {
        $bill = $this->orders->convert(PurchaseOrder::findOrFail($id))->loadMissing(['lines', 'party', 'createdBy']);

        return response()->json(['data' => (new PurchaseBillResource($bill))->resolve($request)], 201)->setEtag((string) $bill->lock_version, true);
    }

    public function pdf(int $id, PurchaseOrderPdf $pdf): Response
    {
        $po = PurchaseOrder::findOrFail($id);

        return response($pdf->render($po), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$pdf->filename($po).'"']);
    }

    private function respond(Request $request, PurchaseOrder $po, int $status = 200): JsonResponse
    {
        $po->loadMissing(['lines', 'party', 'createdBy', 'bills']);

        return response()->json(['data' => (new PurchaseOrderResource($po))->resolve($request)], $status)->setEtag((string) $po->lock_version, true);
    }
}
