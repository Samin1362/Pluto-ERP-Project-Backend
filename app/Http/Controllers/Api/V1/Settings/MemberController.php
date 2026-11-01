<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Domain\Access\AuthService;
use App\Domain\Access\MemberService;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Concerns\ReadsIfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Requests\Settings\InvitationRequest;
use App\Http\Requests\Settings\MemberListRequest;
use App\Http\Requests\Settings\MemberUpdateRequest;
use App\Http\Resources\MeResource;
use App\Models\CompanyUser;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Users and invitations (K8–K10, S3; backlog P15.1-BE-04/05). /users and /invitations need settings.users; the
 * token routes (GET /invitations/{token}, POST /invitations/{token}/accept) are public.
 */
class MemberController extends Controller
{
    use ReadsIfMatch;

    public function __construct(private readonly MemberService $members, private readonly CurrentCompany $current) {}

    public function index(MemberListRequest $request): JsonResponse
    {
        $f = $request->validated();
        $q = CompanyUser::query()->join('users as u', 'u.id', '=', 'company_users.user_id')->select('company_users.*')
            ->when($f['status'] ?? null, fn ($q, $v) => $q->where('company_users.status', $v))
            ->when($f['role_id'] ?? null, fn ($q, $v) => $q->where('company_users.role_id', $v))
            ->when(($f['q'] ?? '') !== '', fn ($q) => $q->where(fn ($w) => $w->where('u.name', 'like', '%'.addcslashes($f['q'], '%_\\').'%')
                ->orWhere('u.email', 'like', '%'.addcslashes($f['q'], '%_\\').'%')));
        $sort = $f['sort'] ?? 'name';
        $q->orderBy(ltrim($sort, '-') === 'last_active' ? 'u.last_login_at' : 'u.name', str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('company_users.id');
        $page = $q->paginate((int) ($f['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn (CompanyUser $m) => $this->members->resource($m))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function show(int $id): JsonResponse
    {
        $m = CompanyUser::findOrFail($id);

        return response()->json(['data' => $this->members->resource($m, true)])->setEtag((string) $m->lock_version, true);
    }

    public function update(MemberUpdateRequest $request, int $id): JsonResponse
    {
        $m = $this->members->update($this->actor(), $id, $request->validated(), $this->ifMatchVersion($request));

        return response()->json(['data' => $this->members->resource($m, true)])->setEtag((string) $m->lock_version, true);
    }

    public function deactivate(int $id): JsonResponse
    {
        return response()->json(['data' => $this->members->resource($this->members->deactivate($this->actor(), $id), true)]);
    }

    public function reactivate(int $id): JsonResponse
    {
        return response()->json(['data' => $this->members->resource($this->members->reactivate($id), true)]);
    }

    public function invitations(): JsonResponse
    {
        $rows = $this->members->invitations();

        return response()->json(['data' => $rows, 'meta' => ['current_page' => 1, 'per_page' => max(count($rows), 1), 'total' => count($rows), 'last_page' => 1]]);
    }

    public function invite(InvitationRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->members->invite($request->validated())], 201);
    }

    public function resend(int $id): JsonResponse
    {
        return response()->json(['data' => $this->members->resend($id)]);
    }

    public function revoke(int $id): Response
    {
        $this->members->revoke($id);

        return response()->noContent();
    }

    /** GET /invitations/{token} (public). */
    public function preview(string $token): JsonResponse
    {
        return response()->json(['data' => $this->members->show($token)])->header('Cache-Control', 'no-store');
    }

    /** POST /invitations/{token}/accept (public): joins the company and signs in (LoginResponse + refresh cookie). */
    public function accept(AcceptInvitationRequest $request, string $token, AuthService $auth): JsonResponse
    {
        ['user' => $user] = $this->members->accept($token, $request->validated());
        $s = $auth->signIn($user, ['pwd'], (string) $request->ip(), $request->userAgent(), 'Invitation');
        $request->attributes->set('auth_user', $user);

        return response()->json(['data' => ['status' => 'authenticated', ...$s['tokens'], 'user' => (new MeResource($user))->resolve($request)]])
            ->withCookie(AuthController::refreshCookie($s['refresh']))->header('Cache-Control', 'no-store');
    }

    private function actor(): CompanyUser
    {
        return $this->current->membership()->load('role');
    }
}
