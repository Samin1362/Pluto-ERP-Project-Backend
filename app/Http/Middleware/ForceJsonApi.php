<?php

namespace App\Http\Middleware;

use App\Support\Problem\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The API only speaks JSON: responses are JSON, and a JSON body that does not parse (or is not an object)
 * is rejected with 400 malformed_json instead of being silently treated as empty (05 §3).
 */
class ForceJsonApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->headers->set('Accept', 'application/json');
        $raw = $request->getContent();
        if ($raw !== '' && $request->isJson()) {
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
                throw new ApiProblem('malformed_json');
            }
        }

        return $next($request);
    }
}
