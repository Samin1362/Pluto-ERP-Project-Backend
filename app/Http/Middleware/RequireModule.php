<?php

namespace App\Http\Middleware;

use App\Domain\Platform\Plans;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plan module check (`module:lc_management`; PEP-498): a part of Pluto the company's plan does not include answers
 * 403 module_not_in_plan for everyone in the company, Pluto support included. Nothing is deleted; switching the
 * module on in the platform console brings the data back.
 */
class RequireModule
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if ($this->current->has() && ! Plans::has($this->current->company(), $module)) {
            $label = Plans::LABELS[$module] ?? $module;
            throw new ApiProblem('module_not_in_plan', "{$label} is not in your Pluto plan; ask Pluto to add it.", extra: ['module' => $module]);
        }

        return $next($request);
    }
}
