<?php

namespace App\Http\Controllers\Api\V1\Purchases;

use App\Domain\Purchases\DebitNotePdf;
use App\Domain\Purchases\DebitNoteService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\DebitNoteRequest;
use App\Http\Requests\Purchases\PurchaseReturnListRequest;
use App\Http\Requests\ReasonRequest;
use App\Http\Resources\DebitNoteResource;
use App\Models\DebitNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** /debit-notes (F7, F13; backlog P10.4-BE-02): drafts, the list, posting per usage, cancelling and the PDF. */
class DebitNoteController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly DebitNoteService $notes) {}

    /** GET /debit-notes (F13): newest first; search covers the DN number, the bill number and the supplier's name. */
    public function index(PurchaseReturnListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = DebitNote::query()->with(['party', 'bill:id,number', 'purchaseReturn:id,number'])->select('debit_notes.*');
        foreach (['party_id', 'status', 'usage'] as $col) {
            if (isset($f[$col])) {
                $q->where("debit_notes.{$col}", $f[$col]);
            }
        }
        if (isset($f['date_from'])) {
            $q->whereDate('debit_notes.note_date', '>=', $f['date_from']);
        }
        if (isset($f['date_to'])) {
            $q->whereDate('debit_notes.note_date', '<=', $f['date_to']);
        }
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->leftJoin('parties as sp', 'sp.id', '=', 'debit_notes.party_id')->leftJoin('purchase_bills as pb', 'pb.id', '=', 'debit_notes.purchase_bill_id')
                ->where(fn ($w) => $w->where('debit_notes.number', 'like', $term)->orWhere('pb.number', 'like', $term)->orWhere('sp.name', 'like', $term));
        }
        $sort = $f['sort'] ?? '-note_date';
        $page = $q->orderBy('debit_notes.'.ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('debit_notes.id', str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));

        return response()->json([
            'data' => $page->getCollection()->map(fn (DebitNote $n) => (new DebitNoteResource($n, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(DebitNoteRequest $request): JsonResponse
    {
        return $this->respond($request, $this->notes->create($request->validated()), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, DebitNote::findOrFail($id));
    }

    public function update(DebitNoteRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);

        return $this->respond($request, $this->notes->update(DebitNote::findOrFail($id), $request->validated(), $version));
    }

    public function destroy(int $id): Response
    {
        $this->notes->delete(DebitNote::findOrFail($id));

        return response()->noContent();
    }

    public function post(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->notes->post(DebitNote::findOrFail($id)));
    }

    public function cancel(ReasonRequest $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->notes->cancel(DebitNote::findOrFail($id), $request->validated('reason')));
    }

    public function pdf(int $id, DebitNotePdf $pdf): Response
    {
        $n = DebitNote::findOrFail($id);

        return response($pdf->render($n), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$n->number.'.pdf"']);
    }

    private function respond(Request $request, DebitNote $n, int $status = 200): JsonResponse
    {
        $n->loadMissing(['party', 'bill:id,number', 'purchaseReturn:id,number', 'createdBy']);

        return response()->json(['data' => (new DebitNoteResource($n))->resolve($request)], $status)->setEtag((string) $n->lock_version, true);
    }
}
