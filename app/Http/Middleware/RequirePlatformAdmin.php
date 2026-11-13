<?php

namespace App\Http\Middleware;

use App\Support\Problem\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /platform/* (PEP-483): only the installation's platform admins — e-mails in PLUTO_PLATFORM_ADMINS (config
 * pluto.platform_admins), never a company role — and only on a 2-step sign-in. Runs after `jwt`.
 */
class RequirePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->attributes->get('auth_user');
        if (! $user || ! in_array(strtolower((string) $user->email), (array) config('pluto.platform_admins', []), true)) {
            throw new ApiProblem('platform_admin_only');
        }
        if (! in_array('otp', (array) $request->attributes->get('auth_amr', []), true)) {
            throw new ApiProblem('platform_two_factor_required', $user->two_factor_confirmed_at === null
                ? 'Turn on 2-step verification in Settings › Security, then sign in again.' : 'Sign in again with your authenticator code.');
        }

        return $next($request);
    }
}
