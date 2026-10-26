<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Domain\Access\PermissionResolver;
use App\Domain\Accounting\VoucherCalculator;
use App\Domain\Accounting\VoucherReversalService;
use App\Domain\Accounting\VoucherService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\ReverseRequest;
use App\Http\Requests\Accounting\VoucherListRequest;
use App\Http\Requests\Accounting\VoucherPostRequest;
use App\Http\Requests\Accounting\VoucherRequest;
use App\Http\Resources\VoucherResource;
use App\Models\CompanyUser;
use App\Models\JournalEntry;
use App\Queries\VoucherQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * /vouchers (H8–H13; backlog P7.1-BE-04). Create, replace, submit, post and calculate check the permission by voucher
 * type (R-09, 05 x-permission-map): journals need accounting.<action>; payments, receipts and contras banking.<action>
 * or accounting.<action>. A voucher that went for approval answers 202 with the voucher (BR-007).
 */
class VoucherController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly VoucherService $vouchers, private readonly VoucherCalculator $calculator, private readonly VoucherQuery $query,
        private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    public function index(VoucherListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $page = $this->query->list($f)->paginate((int) ($f['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (JournalEntry $e) => (new VoucherResource($e, summary: true))->resolve($request))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function store(VoucherRequest $request): JsonResponse
    {
        $in = $request->validated();
        $member = $this->authorizeType($in['entry_type'], 'create');
        [$entry, $approval] = $this->vouchers->create($in, $member);

        return $this->respond($request, $entry, $approval ? 202 : 201)->header('Location', url("/api/v1/vouchers/{$entry->id}"));
    }

    public function calculate(VoucherRequest $request): JsonResponse
    {
        $in = $request->validated();
        $member = $this->authorizeType($in['entry_type'], 'create');

        return response()->json(['data' => $this->calculator->preview($this->calculator->calculate($in, $member))]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->respond($request, JournalEntry::findOrFail($id));
    }

    public function update(VoucherRequest $request, int $id): JsonResponse
    {
        $entry = JournalEntry::findOrFail($id);
        $in = $request->validated();
        $member = $this->authorizeType($entry->entry_type, 'edit');
        $version = $this->ifMatchVersion($request);
        [$entry] = $this->vouchers->update($entry, $in, $version, $member);

        return $this->respond($request, $entry);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->vouchers->delete(JournalEntry::findOrFail($id));

        return response()->json(null, 204);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        $entry = JournalEntry::findOrFail($id);
        $this->authorizeType($entry->entry_type, 'create');

        return $this->respond($request, $this->vouchers->submit($entry));
    }

    public function post(VoucherPostRequest $request, int $id): JsonResponse
    {
        $entry = JournalEntry::findOrFail($id);
        $member = $this->authorizeType($entry->entry_type, 'create');
        [$entry, $approval] = $this->vouchers->post($entry, $request->validated(), $member);

        return $this->respond($request, $entry, $approval ? 202 : 200);
    }

    /** POST /vouchers/{id}/reverse (P7.1-BE-06): 201 with the reversal entry. */
    public function reverse(ReverseRequest $request, int $id, VoucherReversalService $reversals): JsonResponse
    {
        $f = $request->validated();
        $reversal = $reversals->reverse(JournalEntry::findOrFail($id), $f['reversal_date'], $f['reason']);

        return $this->respond($request, $reversal, 201)->header('Location', url("/api/v1/vouchers/{$reversal->id}"));
    }

    /** GET /vouchers/{id}/pdf (H14): the printable A4 voucher. */
    public function pdf(int $id): Response
    {
        $e = JournalEntry::with('createdBy')->findOrFail($id);
        $v = (new VoucherResource($e))->resolve(request());
        $names = DB::table('users')->whereIn('id', array_filter([$e->posted_by, $e->approved_by]))->pluck('name', 'id');
        $pdf = Pdf::loadView('pdf.voucher', ['v' => $v, 'company' => $this->current->company(), 'postedBy' => $names[$e->posted_by] ?? null,
            'approvedBy' => $names[$e->approved_by] ?? null])->setPaper('a4')->setOption('isFontSubsettingEnabled', true);

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "inline; filename=\"{$e->voucher_number}.pdf\""]);
    }

    public function templates(): JsonResponse
    {
        return response()->json(['data' => VoucherQuery::templates()]);
    }

    /** R-09: journal → accounting.<action>; cash vouchers → banking.<action> or accounting.<action>. */
    private function authorizeType(string $type, string $action): CompanyUser
    {
        $member = $this->current->membership();
        $needed = $type === 'journal' ? ["accounting.{$action}"] : ["banking.{$action}", "accounting.{$action}"];
        if (! collect($needed)->contains(fn ($p) => $this->permissions->allows($member, $p))) {
            throw new ApiProblem('permission_denied', 'Your role cannot '.($action === 'edit' ? 'change' : 'enter')." {$type} vouchers.");
        }

        return $member;
    }

    private function respond(Request $request, JournalEntry $entry, int $status = 200): JsonResponse
    {
        $entry = JournalEntry::with('createdBy')->findOrFail($entry->id);

        return response()->json(['data' => (new VoucherResource($entry))->resolve($request)], $status)->setEtag((string) $entry->lock_version, true);
    }
}
