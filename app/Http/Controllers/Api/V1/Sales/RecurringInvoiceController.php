<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Domain\Sales\RecurringInvoiceService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\RecurringTemplateRequest;
use App\Http\Requests\Sales\RecurringInvoiceCreateRequest;
use App\Http\Requests\Sales\RecurringInvoiceListRequest;
use App\Models\RecurringInvoice;
use App\Models\SalesInvoice;
use Illuminate\Http\JsonResponse;

/** Recurring sales invoices (PEP-517): repeat an invoice, list them, pause / change / end one. */
class RecurringInvoiceController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly RecurringInvoiceService $service) {}

    public function store(RecurringInvoiceCreateRequest $request, int $id): JsonResponse
    {
        $t = $this->service->repeat(SalesInvoice::findOrFail($id), $request->validated());

        return response()->json(['data' => RecurringInvoiceService::resource($t)], 201)->setEtag((string) $t->lock_version, true);
    }

    public function index(RecurringInvoiceListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $page = RecurringInvoice::query()->with(['party:id,code,name', 'sourceInvoice:id,number', 'lastInvoice:id,number'])
            ->when(isset($f['active']), fn ($q) => $q->where('is_active', in_array($f['active'], ['true', '1'], true)))
            ->when($f['party_id'] ?? null, fn ($q, $v) => $q->where('party_id', $v))
            ->orderByDesc('is_active')->orderBy('next_run_on')->orderBy('id')->paginate((int) ($f['per_page'] ?? 25), page: (int) ($f['page'] ?? 1));

        return response()->json(['data' => $page->getCollection()->map(fn ($t) => RecurringInvoiceService::resource($t))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function update(RecurringTemplateRequest $request, int $id): JsonResponse
    {
        $t = $this->service->update(RecurringInvoice::findOrFail($id), $request->validated(), $this->ifMatchVersion($request));

        return response()->json(['data' => RecurringInvoiceService::resource($t)])->setEtag((string) $t->lock_version, true);
    }
}
