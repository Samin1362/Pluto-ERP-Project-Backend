<?php

namespace App\Http\Middleware;

use App\Domain\Access\JwtService;
use App\Models\User;
use App\Models\UserSession;
use App\Support\Problem\ApiProblem;
use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 07 §4 step 2: Bearer JWT -> signature, iss, aud, time claims; the user is active; the session (sid) is not
 * revoked; the password version (pwv) is current. Anything else is 401.
 *
 * Implementing AuthenticatesRequests puts this before ThrottleRequests in Laravel's middleware priority, so the
 * per-user limit (api-user) sees the user; without it, every user behind one IP shared one budget.
 */
class AuthenticateJwt implements AuthenticatesRequests
{
    public function __construct(private readonly JwtService $jwt) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (! preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            throw new ApiProblem('unauthenticated');
        }
        $claims = $this->jwt->verify($m[1]);
        $user = User::find($claims['sub']);
        $session = UserSession::find($claims['sid']);
        if (! $user || $user->status !== 'active' || ! $session || $session->user_id !== $user->id
            || $session->revoked_at !== null
            || ! hash_equals($user->passwordVersion(), $claims['pwv'])) {
            throw new ApiProblem('token_invalid');
        }
        $request->attributes->set('auth_user', $user);
        $request->attributes->set('auth_session_id', $session->id);
        $request->attributes->set('auth_session_family', $session->family_id);
        $request->attributes->set('auth_amr', $claims['amr']);
        Auth::guard('api')->setUser($user);

        return $next($request);
    }
}
