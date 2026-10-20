<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The SPA sends its version check as X-If-Match: Vercel's edge evaluates a standard If-Match against the response
 * ETag itself, so a successful edit (new version in the ETag) reached the browser as 412. X-If-Match passes
 * through untouched; here it becomes If-Match again, so controllers keep reading If-Match (BR-049). A real
 * If-Match always wins.
 */
class AcceptXIfMatch
{
    public function handle(Request $request, Closure $next): Response
    {
        $alias = $request->headers->get('X-If-Match');
        if ($alias !== null && ! $request->headers->has('If-Match')) {
            $request->headers->set('If-Match', $alias);
        }

        return $next($request);
    }
}
