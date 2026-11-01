<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domain\Access\AccountService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Me\ChangePasswordRequest;
use App\Http\Requests\Me\NotificationPreferencesRequest;
use App\Http\Requests\Me\PreferencesRequest;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The user's own account (K12–K14, S2; backlog P15.1-BE-07/08): PUT /me/password, /me/sessions, /me/preferences,
 * /me/notification-preferences (switches are per company, so X-Company-Id is needed), and the public
 * POST /auth/password/forgot (always 202) and /auth/password/reset.
 */
class AccountController extends Controller
{
    public function __construct(private readonly AccountService $account) {}

    public function password(ChangePasswordRequest $request): Response
    {
        $f = $request->validated();
        $this->account->changePassword($request->attributes->get('auth_user'), $f['current_password'], $f['password'], (string) $request->attributes->get('auth_session_family'));

        return response()->noContent();
    }

    public function sessions(Request $request): JsonResponse
    {
        $rows = $this->account->sessions((int) auth()->id(), (string) $request->attributes->get('auth_session_family'));

        return response()->json(['data' => $rows, 'meta' => ['current_page' => 1, 'per_page' => max(count($rows), 1), 'total' => count($rows), 'last_page' => 1]]);
    }

    public function revokeSession(int $id): Response
    {
        $this->account->revokeSession((int) auth()->id(), $id);

        return response()->noContent();
    }

    public function revokeOthers(Request $request): Response
    {
        $this->account->revokeOthers((int) auth()->id(), (string) $request->attributes->get('auth_session_family'));

        return response()->noContent();
    }

    public function preferences(): JsonResponse
    {
        return response()->json(['data' => $this->account->preferences((int) auth()->id())]);
    }

    public function savePreferences(PreferencesRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->account->savePreferences((int) auth()->id(), $request->validated())]);
    }

    public function notificationPreferences(CurrentCompany $current): JsonResponse
    {
        return response()->json(['data' => $this->account->notificationPreferences($current->id(), (int) auth()->id())]);
    }

    public function saveNotificationPreferences(NotificationPreferencesRequest $request, CurrentCompany $current): JsonResponse
    {
        return response()->json(['data' => $this->account->saveNotificationPreferences($current->id(), (int) auth()->id(), $request->validated())]);
    }

    public function forgot(ForgotPasswordRequest $request): Response
    {
        $this->account->forgot($request->validated()['email']);

        return response()->noContent(202);
    }

    public function reset(ResetPasswordRequest $request): Response
    {
        $f = $request->validated();
        $this->account->reset($f['email'], $f['token'], $f['password']);

        return response()->noContent();
    }
}
