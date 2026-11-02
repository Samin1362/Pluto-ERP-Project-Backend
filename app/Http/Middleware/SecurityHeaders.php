<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every response (P17.1-BE-03; ZAP baseline TC-NFR-009 warnings 10021, 10038, 10063, 90004). JSON
 * answers get a CSP that allows nothing (an API response is never a page). Files (PDFs, attachments, logo, exports)
 * keep no CSP so the browser's PDF viewer can show them in a tab, but still may not be framed or sniffed.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'no-referrer');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $h->set('Cross-Origin-Resource-Policy', 'same-origin');
        $h->set('X-Frame-Options', 'DENY');
        $type = (string) $h->get('Content-Type', '');
        if ($type === '' || str_contains($type, 'json') || str_starts_with($type, 'text/html')) {
            $h->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }

        return $response;
    }
}
