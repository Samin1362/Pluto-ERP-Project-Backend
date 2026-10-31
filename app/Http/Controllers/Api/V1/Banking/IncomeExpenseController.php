<?php

namespace App\Http\Controllers\Api\V1\Banking;

use App\Domain\Access\PermissionResolver;
use App\Domain\Banking\IncomeExpenseService;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\IeCategoryRequest;
use App\Http\Requests\Banking\IeEntryRequest;
use App\Http\Requests\Banking\IncomeExpenseQueryRequest;
use App\Http\Requests\Banking\RecurringTemplateRequest;
use App\Http\Resources\VoucherResource;
use App\Models\IeCategory;
use App\Models\JournalEntry;
use App\Models\RecurringTemplate;
use App\Queries\IncomeExpenseQuery;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use App\Support\Reports\ReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Income and expenses (I1–I10; backlog P13.1-BE-02..05): categories with budgets, entries (posted lines on category
 * ledgers, GAP-16), recording an income or expense voucher (202 when it waits for approval, BR-007), recurring
 * templates, the overview and the income / expense reports (reports.financial, DEC-13; exports need reports.export).
 */
class IncomeExpenseController extends Controller
{
    use ReadsIfMatch;

    public function __construct(
        private readonly CurrentCompany $current,
        private readonly IncomeExpenseService $service,
        private readonly IncomeExpenseQuery $query,
        private readonly PermissionResolver $permissions,
        private readonly ReportExporter $exporter,
    ) {}

    public function categories(IncomeExpenseQueryRequest $request): JsonResponse
    {
        $rows = $this->query->categories($this->current->id(), $request->validated('kind') ?? 'expense', $request->validated('period') ?? 'this_month');

        return response()->json(['data' => $rows, 'meta' => ['current_page' => 1, 'per_page' => max(1, count($rows)), 'total' => count($rows), 'last_page' => 1]]);
    }

    public function storeCategory(IeCategoryRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->categoryRow($this->service->createCategory($request->validated()))], 201);
    }

    public function updateCategory(IeCategoryRequest $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->categoryRow($this->service->updateCategory(IeCategory::findOrFail($id), $request->validated()))]);
    }

    public function entries(IncomeExpenseQueryRequest $request): JsonResponse
    {
        $f = $request->validated();
        [$rows, $total, $sum] = $this->query->entries($this->current->id(), $f);
        $per = (int) ($f['per_page'] ?? 25);

        return response()->json(['data' => $rows, 'meta' => ['current_page' => (int) ($f['page'] ?? 1), 'per_page' => $per, 'total' => $total,
            'last_page' => max(1, (int) ceil($total / $per)), 'total_amount' => $sum]]);
    }

    public function storeEntry(IeEntryRequest $request): JsonResponse
    {
        [$entry, $approval] = $this->service->record($request->validated(), $this->current->membership());
        $entry = JournalEntry::with('createdBy')->findOrFail($entry->id);

        return response()->json(['data' => (new VoucherResource($entry))->resolve($request)], $approval ? 202 : 201)
            ->header('Location', url("/api/v1/vouchers/{$entry->id}"))->setEtag((string) $entry->lock_version, true);
    }

    public function templates(IncomeExpenseQueryRequest $request): JsonResponse
    {
        $f = $request->validated();
        $page = RecurringTemplate::query()->when($f['kind'] ?? null, fn ($q, $v) => $q->where('kind', $v))
            ->when(isset($f['active']), fn ($q) => $q->where('is_active', in_array($f['active'], ['true', '1'], true)))
            ->orderByDesc('is_active')->orderBy('next_run_on')->orderBy('id')->paginate((int) ($f['per_page'] ?? 25), page: (int) ($f['page'] ?? 1));
        $names = IeCategory::whereIn('id', $page->getCollection()->map(fn ($t) => $t->payload['category_id'] ?? 0))->pluck('name', 'id');

        return response()->json(['data' => $page->getCollection()->map(fn ($t) => $this->template($t, $names))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function updateTemplate(RecurringTemplateRequest $request, int $id): JsonResponse
    {
        $version = $this->ifMatchVersion($request);
        $t = $this->service->updateTemplate(RecurringTemplate::findOrFail($id), $request->validated(), $version);
        $names = IeCategory::whereKey($t->payload['category_id'] ?? 0)->pluck('name', 'id');

        return response()->json(['data' => $this->template($t, $names)])->setEtag((string) $t->lock_version, true);
    }

    public function overview(IncomeExpenseQueryRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->query->overview($this->current->id(), $request->validated('kind') ?? 'expense', $request->validated('period') ?? 'this_year')]);
    }

    public function incomeReport(IncomeExpenseQueryRequest $request): Response
    {
        return $this->report($request, 'income');
    }

    public function expenseReport(IncomeExpenseQueryRequest $request): Response
    {
        return $this->report($request, 'expense');
    }

    private function report(Request $request, string $kind): Response
    {
        $f = $request->validated();
        $report = $this->query->report($this->current->id(), $kind, $f);
        $format = $f['format'] ?? 'json';
        if ($format === 'json') {
            return response()->json(['data' => $report]);
        }
        if (! $this->permissions->allows($this->current->membership(), 'reports.export')) {
            throw new ApiProblem('permission_denied', 'Your role does not include reports.export.');
        }

        return $this->exporter->download($report, $format, $kind === 'income' ? 'income-report' : 'expense-report');
    }

    private function categoryRow(IeCategory $c): array
    {
        $rows = collect($this->query->categories($this->current->id(), $c->kind, 'this_month'));

        return $rows->firstWhere('id', $c->id);
    }

    /** RecurringTemplate (05) plus what I10 shows: a description, the end settings and the latest draft. */
    private function template(RecurringTemplate $t, $names): array
    {
        $p = $t->payload;
        $description = trim(($names[$p['category_id'] ?? 0] ?? ucfirst($t->kind)).(! empty($p['payee_text']) ? ' — '.$p['payee_text'] : ''));

        return ['id' => $t->id, 'kind' => $t->kind, 'frequency' => $t->frequency, 'next_run_on' => $t->next_run_on->toDateString(), 'ends_type' => $t->ends_type,
            'ends_after_count' => $t->ends_after_count, 'ends_on' => $t->ends_on?->toDateString(), 'occurrences_done' => $t->occurrences_done,
            'is_active' => $t->is_active, 'payload' => $p, 'description' => $description, 'last_entry_id' => $t->last_entry_id, 'version' => $t->lock_version];
    }
}
