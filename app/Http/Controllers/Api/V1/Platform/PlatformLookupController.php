<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domain\Access\PermissionResolver;
use App\Domain\Shared\AuditableTypes;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\AuditLogListRequest;
use App\Http\Requests\Platform\BranchListRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\BranchResource;
use App\Http\Resources\CompanySettingsResource;
use App\Http\Resources\FiscalYearResource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform lookups every member needs (backlog P1.1-BE-01): financial years with period status, active branches
 * for pickers and filters, and the company's formatting/control settings. Read only; editing comes with P15.
 * Plus the activity of one record for detail-page timelines (P1.1-BE-02).
 */
class PlatformLookupController extends Controller
{
    public function __construct(private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    /** GET /fiscal-years: newest first, each with its periods in date order. */
    public function fiscalYears(Request $request): JsonResponse
    {
        $this->noUnknownParams($request);
        $years = FiscalYear::with('periods.lockedBy')->orderByDesc('start_date')->get();

        return response()->json([
            'data' => FiscalYearResource::collection($years)->resolve($request),
            'meta' => ['current_page' => 1, 'per_page' => max($years->count(), 1), 'total' => $years->count(), 'last_page' => 1],
        ]);
    }

    /** GET /branches: active locations, searchable by code or name. */
    public function branches(BranchListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = Branch::where('is_active', true);
        if (($f['q'] ?? '') !== '') {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term));
        }
        $sort = $f['sort'] ?? 'name';
        $page = $q->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('id')
            ->paginate((int) ($f['per_page'] ?? 25));

        return response()->json([
            'data' => BranchResource::collection($page->getCollection())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** GET /company/settings: the company's single settings row; ETag carries its version (BR-049). */
    public function companySettings(Request $request): JsonResponse
    {
        $this->noUnknownParams($request);
        $settings = CompanySetting::firstOrFail();
        $admin = $this->permissions->allows($this->current->membership(), 'settings.view');

        return response()->json(['data' => (new CompanySettingsResource($settings, $admin))->resolve($request)])
            ->setEtag($settings->lock_version, true);
    }

    /**
     * GET /audit-logs?auditable_type=&auditable_id=: newest first. Needs the view permission of the record's module
     * (07 §6.2); a record outside the company answers 404 like any other lookup (BR-037).
     */
    public function auditLogs(AuditLogListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $type = AuditableTypes::get($f['auditable_type']);
        if (! $this->permissions->allows($this->current->membership(), $type['permission'])) {
            throw new ApiProblem('permission_denied', "Your role does not include {$type['permission']}.");
        }
        $exists = DB::table($type['table'])->where('company_id', $this->current->id())->where('id', $f['auditable_id'])->exists();
        if (! $exists) {
            throw new ApiProblem('not_found');
        }
        $page = AuditLog::with('user')->where('company_id', $this->current->id())
            ->where('auditable_type', $type['table'])->where('auditable_id', $f['auditable_id'])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => AuditLogResource::collection($page->getCollection())->resolve($request),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    private function noUnknownParams(Request $request): void
    {
        if ($unknown = array_keys($request->query())) {
            throw new ApiProblem('validation_failed', errors: array_fill_keys($unknown, ['This parameter is not part of the API.']));
        }
    }
}
