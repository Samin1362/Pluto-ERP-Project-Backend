<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Access\AuthService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\TwoFactorRequest;
use App\Http\Resources\MeResource;
use App\Support\AllowedOrigins;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

/** POST /auth/login, /auth/two-factor, /auth/refresh, /auth/logout (05 Auth tag). Thin: validate -> service -> response. */
class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $d = $request->validated();
        $r = $this->auth->login($d['email'], $d['password'], (string) $request->ip(), $request->userAgent(), $d['device_label'] ?? null);
        if (isset($r['challenge_token'])) {
            // 2-step is on: no tokens and no cookie until the code is checked (POST /auth/two-factor).
            return response()->json(['data' => ['status' => 'two_factor_required', 'challenge_token' => $r['challenge_token']]])
                ->header('Cache-Control', 'no-store');
        }

        return $this->signedIn($request, $r);
    }

    public function twoFactor(TwoFactorRequest $request): JsonResponse
    {
        $d = $request->validated();
        $r = $this->auth->completeTwoFactor($d['challenge_token'], $d['code'] ?? null, $d['recovery_code'] ?? null,
            (string) $request->ip(), $request->userAgent());

        return $this->signedIn($request, $r);
    }

    private function signedIn(Request $request, array $r): JsonResponse
    {
        return response()->json(['data' => ['status' => 'authenticated'] + $r['tokens'] + ['user' => (new MeResource($r['user']))->resolve($request)]])
            ->withCookie($this->cookie($r['refresh']))->header('Cache-Control', 'no-store');
    }

    public function refresh(Request $request): JsonResponse
    {
        if (! $this->trustedCookieRequest($request)) {
            throw new ApiProblem('refresh_token_invalid', 'Refresh must come from the Pluto app.');
        }
        try {
            $r = $this->auth->refresh($request->cookie(config('pluto.refresh.cookie')), (string) $request->ip(), $request->userAgent());
        } catch (ApiProblem $e) {
            throw new ApiProblem($e->errorCode, $e->detail, headers: ['Set-Cookie' => (string) $this->cookie('', true)]);
        }

        return response()->json(['data' => $r['tokens']])->withCookie($this->cookie($r['refresh']))->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): Response
    {
        if ($this->trustedCookieRequest($request)) {
            $this->auth->logout($request->cookie(config('pluto.refresh.cookie')));
        }

        return response()->noContent()->withCookie($this->cookie('', true));
    }

    private function cookie(string $value, bool $forget = false): Cookie
    {
        return Cookie::create(config('pluto.refresh.cookie'), $forget ? null : $value,
            $forget ? 1 : now()->addDays((int) config('pluto.refresh.ttl_days')),
            config('pluto.refresh.path'), null, (bool) config('pluto.refresh.secure'), true, false, Cookie::SAMESITE_STRICT);
    }

    /** CSRF defence for the cookie-authenticated endpoints (07 §3.2): custom header + allowed Origin (exact or pattern) when sent. */
    private function trustedCookieRequest(Request $request): bool
    {
        if ($request->headers->get('X-Requested-With') !== 'XMLHttpRequest') {
            return false;
        }
        $origin = $request->headers->get('Origin');

        return $origin === null || AllowedOrigins::allows($origin);
    }
}
