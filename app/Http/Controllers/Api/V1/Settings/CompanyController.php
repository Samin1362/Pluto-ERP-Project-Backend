<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Access\CompanyService;
use App\Domain\Access\PermissionResolver;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\BranchRequest;
use App\Http\Requests\Settings\CompanyCreateRequest;
use App\Http\Requests\Settings\CompanySettingsRequest;
use App\Http\Requests\Settings\CompanyUpdateRequest;
use App\Http\Requests\Settings\LogoRequest;
use App\Http\Requests\Settings\NumberSequenceRequest;
use App\Http\Requests\Settings\TaxRuleRequest;
use App\Http\Resources\CompanySettingsResource;
use App\Http\Resources\TaxRuleResource;
use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company and settings (K1–K7, S0; backlog P15.1-BE-01..03): POST /companies, /company, /company/logo, /branches,
 * PATCH /company/settings, /number-sequences, /tax-rules. Route permissions: settings.view / settings.edit.
 */
class CompanyController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly CompanyService $companies, private readonly CurrentCompany $current) {}

    /** POST /companies: no X-Company-Id; idempotent per user (R-21). */
    public function store(CompanyCreateRequest $request): JsonResponse
    {
        $company = $this->companies->create($request->validated(), $request->attributes->get('auth_user'));

        return response()->json(['data' => $this->companies->resource($company->refresh())], 201)->header('Location', url('/api/v1/company'));
    }

    public function show(): JsonResponse
    {
        return $this->respond(Company::findOrFail($this->current->id()));
    }

    public function update(CompanyUpdateRequest $request): JsonResponse
    {
        return $this->respond($this->companies->update($request->validated(), $this->ifMatchVersion($request)));
    }

    public function logo(LogoRequest $request): JsonResponse
    {
        return $this->respond($this->companies->setLogo($request->file('file'), $this->ifMatchVersion($request)));
    }

    public function removeLogo(): Response
    {
        $this->companies->removeLogo();

        return response()->noContent();
    }

    /** The logo file behind Company.logo_url (signed, outside JWT; SVG served without script). */
    public function logoFile(int $id): StreamedResponse
    {
        $path = Company::whereKey($id)->value('logo_path') ?? abort(404);

        return Storage::disk('local')->response($path, null, ['Content-Type' => str_ends_with($path, '.svg') ? 'image/svg+xml' : 'image/png',
            'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'", 'Cache-Control' => 'private, max-age=3600']);
    }

    public function storeBranch(BranchRequest $request): JsonResponse
    {
        $b = $this->companies->saveBranch($request->validated());

        return response()->json(['data' => CompanyService::branch($b)], 201)->setEtag((string) $b->lock_version, true)->header('Location', url("/api/v1/branches/{$b->id}"));
    }

    public function updateBranch(BranchRequest $request, int $id): JsonResponse
    {
        $b = $this->companies->saveBranch($request->validated(), $id, $this->ifMatchVersion($request));

        return response()->json(['data' => CompanyService::branch($b)])->setEtag((string) $b->lock_version, true);
    }

    public function updateSettings(CompanySettingsRequest $request, PermissionResolver $permissions): JsonResponse
    {
        $member = $this->current->membership()->load('role');
        $s = $this->companies->updateSettings($request->validated(), $this->ifMatchVersion($request), $member, $request->attributes->get('auth_user'));

        return response()->json(['data' => (new CompanySettingsResource($s, $permissions->allows($member, 'settings.view')))->resolve($request)])->setEtag((string) $s->lock_version, true);
    }

    public function sequences(Request $request): JsonResponse
    {
        if ($unknown = array_keys($request->query())) {
            throw new ApiProblem('validation_failed', errors: array_fill_keys($unknown, ['This parameter is not part of the API.']));
        }
        $rows = $this->companies->sequences();

        return response()->json(['data' => $rows, 'meta' => ['current_page' => 1, 'per_page' => max(count($rows), 1), 'total' => count($rows), 'last_page' => 1]]);
    }

    public function updateSequence(NumberSequenceRequest $request, int $id): JsonResponse
    {
        $s = $this->companies->updateSequence($id, $request->validated(), $this->ifMatchVersion($request));

        return response()->json(['data' => CompanyService::sequence($s)])->setEtag((string) $s->lock_version, true);
    }

    public function storeTaxRule(TaxRuleRequest $request): JsonResponse
    {
        $r = $this->companies->saveTaxRule($request->validated());

        return response()->json(['data' => (new TaxRuleResource($r))->resolve($request)], 201)->setEtag((string) $r->lock_version, true);
    }

    public function updateTaxRule(TaxRuleRequest $request, int $id): JsonResponse
    {
        $r = $this->companies->saveTaxRule($request->validated(), $id, $this->ifMatchVersion($request));

        return response()->json(['data' => (new TaxRuleResource($r))->resolve($request)])->setEtag((string) $r->lock_version, true);
    }

    private function respond(Company $c): JsonResponse
    {
        return response()->json(['data' => $this->companies->resource($c->refresh())])->setEtag((string) $c->lock_version, true);
    }
}
