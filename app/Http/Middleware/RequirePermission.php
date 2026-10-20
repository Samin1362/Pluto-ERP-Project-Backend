<?php

namespace App\Http\Middleware;

use App\Domain\Access\PermissionResolver;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Route permission check (`perm:parties.view`), resolved from the membership's role on every request (07 §6.2). */
class RequirePermission
{
    public function __construct(private readonly CurrentCompany $current, private readonly PermissionResolver $permissions) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $membership = $this->current->membership();
        if (! $membership || ! $this->permissions->allows($membership, $permission)) {
            throw new ApiProblem('permission_denied', "Your role does not include {$permission}.");
        }

        return $next($request);
    }
}
