<?php

namespace App\Http\Controllers\Api\V1\Purchases;

use App\Domain\Purchases\PurchaseBillCancellationService;
use App\Domain\Purchases\PurchaseBillPostingService;
use App\Domain\Purchases\PurchaseBillPreparer;
use App\Domain\Purchases\PurchaseBillService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\CancelRequest;
use App\Http\Requests\PostRequest;
use App\Http\Requests\Purchases\PurchaseBillListRequest;
use App\Http\Requests\Purchases\PurchaseBillRequest;
use App\Http\Resources\PurchaseBillResource;
use App\Models\PurchaseBill;
use App\Queries\PurchaseBillQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /purchase-bills (F2–F4; backlog P4.2): calculate, draft create/read/replace/delete. */
class PurchaseBillController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly PurchaseBillPreparer $preparer, private readonly PurchaseBillService $bills) {}

    /** DocumentCalculation (05): authoritative totals for an unsaved bill; the UI renders only these (DEC-09). */
    public function calculate(PurchaseBillRequest $request): JsonResponse
    {
        $b = $this->preparer->prepare($request->validated());
        $c = $b->calculation;

        return response()->json(['data' => [
            'lines' => array_map(fn ($l, $i) => [
                'line_no' => $l->lineNo, 'gross' => (string) $l->gross, 'discount_amount' => (string) $l->discountTotal(), 'tax_amount' => (string) $l->tax,
                'line_total' => (string) $l->total, 'available_qty' => $b->lines[$i]['available'],
            ], $c->lines, array_keys($c->lines)),
            'warnings' => $b->warnings,
            'party_balance_before' => (string) $b->partyBalanceBefore,
            'party_balance_after' => (string) $b->partyBalanceBefore->plus($c->baseTotal),
            'credit_limit' => $b->party->credit_limit,
            'effects' => $b->effects,
            'subtotal' => (string) $c->subtotal,
            'discount_total' => (string) $c->discountTotal,
            'tax_total' => (string) $c->taxTotal,
            'rounding_adjustment' => (string) $c->rounding,
            'total' => (string) $c->total,
            'amount_in_words' => $c->amountInWords,
        ]]);
    }

    /** GET /purchase-bills (F2): bill headers; lines and journal only on the detail (GET /purchase-bills/{id}). */
    public function index(PurchaseBillListRequest $request, PurchaseBillQuery $query): JsonResponse
    {
        $page = $query->page($request->validated());

        return response()->json([
            'data' => $page->getCollection()->map(fn (PurchaseBill $b) => (new PurchaseBillResource($b, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(PurchaseBillRequest $request): JsonResponse
    {
        return $this->respond($request, $this->bills->createDraft($request->validated()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, PurchaseBill::with('lines')->findOrFail($id));
    }

    public function update(PurchaseBillRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->bills->updateDraft(PurchaseBill::findOrFail($id), $request->validated(), $version));
    }

    /** POST /purchase-bills/{id}/post (P4.3-BE-01): stock in, average cost, journal, supplier balance. */
    public function post(PostRequest $request, int $id, PurchaseBillPostingService $posting): JsonResponse
    {
        return $this->respond($request, $posting->post(PurchaseBill::findOrFail($id), (bool) $request->validated('confirm_backdated', false)));
    }

    /** POST /purchase-bills/{id}/cancel (P4.3-BE-02): reversing entry, stock back out at bill cost. */
    public function cancel(CancelRequest $request, int $id, PurchaseBillCancellationService $cancellation): JsonResponse
    {
        $f = $request->validated();

        return $this->respond($request, $cancellation->cancel(PurchaseBill::findOrFail($id), $f['reason'], $f['note'] ?? null, $f['cancellation_date'] ?? null));
    }

    public function destroy(int $id): Response
    {
        $this->bills->deleteDraft(PurchaseBill::findOrFail($id));

        return response()->noContent();
    }

    private function respond(Request $request, PurchaseBill $bill, int $status = 200): JsonResponse
    {
        $bill->loadMissing(['lines', 'party', 'createdBy']);

        return response()->json(['data' => (new PurchaseBillResource($bill))->resolve($request)], $status)->setEtag((string) $bill->lock_version, true);
    }
}
