<?php

namespace App\Http\Controllers\Api\V1\Settlements;

use App\Domain\Settlements\PartyPaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settlements\CustomerReceiptListRequest;
use App\Http\Requests\Settlements\PartyPaymentRequest;
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

/** /customer-receipts (E7, E14; backlog P6.1-BE-03): receive money from a customer, list, show, receipt PDF. */
class CustomerReceiptController extends Controller
{
    public function store(PartyPaymentRequest $request, PartyPaymentService $payments): JsonResponse
    {
        return $this->respond($request, $payments->receive($request->validated()), 201);
    }

    public function index(CustomerReceiptListRequest $request, CustomerReceiptQuery $query): JsonResponse
    {
        $page = $query->page($request->validated());

        return response()->json([
            'data' => $page->getCollection()->map(fn (JournalEntry $e) => (new VoucherResource($e, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, $this->find($id));
    }

    /** The money receipt as an A4 PDF (E14 / E7 "Print receipt"). */
    public function pdf(int $id, CurrentCompany $current): Response
    {
        $e = $this->find($id);
        $voucher = (new VoucherResource($e))->resolve(request());
        $pdf = Pdf::loadView('pdf.money-receipt', ['v' => $voucher, 'company' => $current->company()])->setPaper('a5', 'landscape')->setOption('isFontSubsettingEnabled', true);

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"{$e->voucher_number}.pdf\""]);
    }

    /** A customer receipt of this company (a receipt entry whose payment party is a customer), else 404. */
    private function find(int $id): JournalEntry
    {
        $e = JournalEntry::with('createdBy')->where('entry_type', 'receipt')->findOrFail($id);
        $isCustomer = DB::table('payment_details as p')->join('parties as y', 'y.id', '=', 'p.party_id')->where('p.journal_entry_id', $e->id)->where('y.party_type', 'customer')->exists();
        if (! $isCustomer) {
            throw new ApiProblem('not_found');
        }

        return $e;
    }

    private function respond(Request $request, JournalEntry $e, int $status = 200): JsonResponse
    {
        $e->loadMissing('createdBy');

        return response()->json(['data' => (new VoucherResource($e))->resolve($request)], $status)->setEtag((string) $e->lock_version, true);
    }
}
