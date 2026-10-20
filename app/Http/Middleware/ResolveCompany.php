<?php

namespace App\Http\Middleware;

use App\Domain\Access\TwoFactorService;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 07 §4 step 4: X-Company-Id must name a company where the caller has an ACTIVE membership.
 * `company:optional` (GET /me) binds the company only when the header is present.
 */
class ResolveCompany
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $this->current->clear();
        $raw = $request->headers->get('X-Company-Id');
        if ($raw === null || $raw === '') {
            if ($mode === 'optional') {
                return $next($request);
            }
            throw new ApiProblem('company_required');
        }
        if (! ctype_digit($raw)) {
            throw new ApiProblem('company_required', 'X-Company-Id must be a numeric company id');
        }
        $user = $request->attributes->get('auth_user');
        $company = Company::find((int) $raw);
        $membership = $company ? CompanyUser::withoutGlobalScope('company')
            ->where('company_id', $company->id)->where('user_id', $user->id)->where('status', 'active')->first() : null;
        if (! $company || ! $membership || $company->status !== 'active') {
            throw new ApiProblem('not_a_member');
        }
        // 07 §4 step 3: a company that requires 2-step refuses sign-ins made without a code. GET /me
        // (optional mode) still answers so the app can send the user to set it up.
        if ($mode !== 'optional' && ! in_array('otp', (array) $request->attributes->get('auth_amr', []), true)
            && app(TwoFactorService::class)->requiredBy($company->id)) {
            throw new ApiProblem('two_factor_required_by_company',
                $user->two_factor_confirmed_at === null ? 'Turn on 2-step verification in Settings › Security, then continue.' : 'Sign in again with your authenticator code.');
        }
        $this->current->set($company, $membership);

        return $next($request);
    }
}
