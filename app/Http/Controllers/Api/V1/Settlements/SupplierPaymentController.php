<?php

namespace App\Http\Controllers\Api\V1\Settlements;

use App\Domain\Settlements\PartyPaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlements\SupplierPaymentListRequest;
use App\Http\Requests\Settlements\SupplierPaymentRequest;
use App\Http\Resources\VoucherResource;
use App\Models\JournalEntry;
use App\Queries\CustomerReceiptQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * /supplier-payments (F5, F12, Pur-DlgPay; backlog P8.1-BE-01): pay a supplier (201 posted, or 202 submitted above the
 * role's payment limit), list, show and the A4 payment voucher.
 */
class SupplierPaymentController extends Controller
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function store(SupplierPaymentRequest $request, PartyPaymentService $payments): JsonResponse
    {
        [$entry, $approval] = $payments->pay($request->validated(), $this->current->membership());

        return $this->respond($request, $entry, $approval ? 202 : 201)->header('Location', url("/api/v1/supplier-payments/{$entry->id}"));
    }

    public function index(SupplierPaymentListRequest $request, CustomerReceiptQuery $query): JsonResponse
    {
        $page = $query->page($request->validated(), 'payment', 'supplier');

        return response()->json([
            'data' => $page->getCollection()->map(fn (JournalEntry $e) => (new VoucherResource($e, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->find($id));
    }

    public function pdf(int $id): Response
    {
        $e = $this->find($id);
        $v = (new VoucherResource($e))->resolve(request());
        $names = DB::table('users')->whereIn('id', array_filter([$e->posted_by, $e->approved_by]))->pluck('name', 'id');
        $pdf = Pdf::loadView('pdf.voucher', ['v' => $v, 'company' => $this->current->company(), 'postedBy' => $names[$e->posted_by] ?? null,
            'approvedBy' => $names[$e->approved_by] ?? null])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"{$e->voucher_number}.pdf\""]);
    }

    /** A supplier payment of this company (a payment entry whose payment party is a supplier), else 404. */
    private function find(int $id): JournalEntry
    {
        $e = JournalEntry::with('createdBy')->where('entry_type', 'payment')->findOrFail($id);
        $toSupplier = DB::table('payment_details as p')->join('parties as y', 'y.id', '=', 'p.party_id')->where('p.journal_entry_id', $e->id)->where('y.party_type', 'supplier')->exists();
        if (! $toSupplier) {
            throw new ApiProblem('not_found');
        }

        return $e;
    }

    private function respond(Request $request, JournalEntry $e, int $status = 200): JsonResponse
    {
        $e = JournalEntry::with('createdBy')->findOrFail($e->id);

        return response()->json(['data' => (new VoucherResource($e))->resolve($request)], $status)->setEtag((string) $e->lock_version, true);
    }
}
