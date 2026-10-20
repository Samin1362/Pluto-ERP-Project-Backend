<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Domain\Access\TwoFactorService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\PasswordConfirmRequest;
use App\Http\Requests\Auth\TwoFactorConfirmRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** POST /me/two-factor, POST /me/two-factor/confirm, DELETE /me/two-factor (K14, FR-004). */
class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function start(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->twoFactor->start($request->attributes->get('auth_user'))])->header('Cache-Control', 'no-store');
    }

    public function confirm(TwoFactorConfirmRequest $request): JsonResponse
    {
        $codes = $this->twoFactor->confirm($request->attributes->get('auth_user'), $request->validated()['code'],
            (string) $request->attributes->get('auth_session_family'));

        return response()->json(['data' => ['recovery_codes' => $codes]], 201)->header('Cache-Control', 'no-store');
    }

    public function destroy(PasswordConfirmRequest $request): Response
    {
        $this->twoFactor->disable($request->attributes->get('auth_user'), $request->validated()['password']);

        return response()->noContent();
    }
}
