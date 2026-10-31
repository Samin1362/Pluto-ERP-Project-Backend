<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Sales\CreditNotePdf;
use App\Domain\Sales\CreditNoteService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReasonRequest;
use App\Http\Requests\Sales\CreditNoteRequest;
use App\Http\Requests\Sales\SalesReturnListRequest;
use App\Http\Resources\CreditNoteResource;
use App\Models\CreditNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /credit-notes (E9, E15; backlog P10.3-BE-02): drafts, the list, posting per usage, cancelling and the PDF. */
class CreditNoteController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly CreditNoteService $notes) {}

    /** GET /credit-notes (E15): newest first; search covers the CN number, the invoice number and the customer's name. */
    public function index(SalesReturnListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = CreditNote::query()->with(['party', 'invoice:id,number', 'salesReturn:id,number'])->select('credit_notes.*');
        foreach (['party_id', 'status', 'usage'] as $col) {
            if (isset($f[$col])) {
                $q->where("credit_notes.{$col}", $f[$col]);
            }
        }
        if (isset($f['date_from'])) {
            $q->whereDate('credit_notes.note_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('credit_notes.note_date', '<=', $f['date_to']);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->leftJoin('parties as cp', 'cp.id', '=', 'credit_notes.party_id')->leftJoin('sales_invoices as si', 'si.id', '=', 'credit_notes.sales_invoice_id')
                ->where(fn ($w) => $w->where('credit_notes.number', 'like', $term)->orWhere('si.number', 'like', $term)->orWhere('cp.name', 'like', $term));
        }
        $sort = $f['sort'] ?? '-note_date';
        $page = $q->orderBy('credit_notes.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('credit_notes.id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (CreditNote $n) => (new CreditNoteResource($n, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(CreditNoteRequest $request): JsonResponse
    {
        return $this->respond($request, $this->notes->create($request->validated()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, CreditNote::findOrFail($id));
    }

    public function update(CreditNoteRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->notes->update(CreditNote::findOrFail($id), $request->validated(), $version));
    }

    public function destroy(int $id): Response
    {
        $this->notes->delete(CreditNote::findOrFail($id));

        return response()->noContent();
    }

    public function post(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->notes->post(CreditNote::findOrFail($id)));
    }

    public function cancel(ReasonRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->notes->cancel(CreditNote::findOrFail($id), $request->validated('reason')));
    }

    public function pdf(int $id, CreditNotePdf $pdf): Response
    {
        $n = CreditNote::findOrFail($id);

        return response($pdf->render($n), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$n->number.'.pdf"']);
    }

    private function respond(Request $request, CreditNote $n, int $status = 200): JsonResponse
    {
        $n->loadMissing(['party', 'invoice:id,number', 'salesReturn:id,number', 'createdBy']);

        return response()->json(['data' => (new CreditNoteResource($n))->resolve($request)], $status)->setEtag((string) $n->lock_version, true);
    }
}
