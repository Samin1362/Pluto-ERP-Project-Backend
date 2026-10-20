<?php

namespace App\Http\Middleware;

use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Accepts a valid X-Request-Id or generates one; echoes it and adds it to every log line (07 §4, 03 §8). */
class RequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->headers->get('X-Request-Id', '');
        if (! Str::isUuid($id)) {
            $id = (string) Str::uuid();
        }
        $request->attributes->set('request_id', $id);
        app(CurrentCompany::class)->clear(); // never carry a company over from a previous request
        Log::withContext(['request_id' => $id]);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    public static function current(Request $request): string
    {
        $id = $request->attributes->get('request_id');
        if (! $id) {
            $id = (string) Str::uuid();
            $request->attributes->set('request_id', $id);
        }

        return $id;
    }
}
