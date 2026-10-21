<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real client IP, scheme and host behind the Vercel proxy and the Cloudflare tunnel (backlog P0.3-BE-02/03).
 *
 * Only a request carrying `X-Pluto-Proxy-Secret` equal to PROXY_SHARED_SECRET comes from our own proxy. For it, the
 * first X-Forwarded-For address becomes the client IP (rate limits, login throttling, audit), and
 * X-Forwarded-Proto/-Host become the URL root (Location headers show the Vercel domain, not the tunnel). For any
 * other request the forwarded headers are dropped, so nobody can choose their own IP. The secret header is removed
 * before anything else can read or log it. Runs first in the global stack; Laravel's TrustProxies trusts nobody.
 *
 * Rename to Pluto (backlog P1.7-BE-01): the old header `X-LF-Proxy-Secret` is still accepted for one release so the
 * live switch (P1.7-BE-04) has no gap while Vercel still runs the old proxy; remove LEGACY_HEADER after it.
 */
class TrustPlutoProxy
{
    private const SECRET_HEADER = 'X-Pluto-Proxy-Secret';

    private const LEGACY_HEADER = 'X-LF-Proxy-Secret';

    private const FORWARDED = ['X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Proto', 'X-Forwarded-Port', 'X-Forwarded-Prefix', 'Forwarded'];

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('pluto.proxy.shared_secret');
        $sent = (string) ($request->headers->get(self::SECRET_HEADER) ?? $request->headers->get(self::LEGACY_HEADER, ''));
        $trusted = $secret !== '' && $sent !== '' && hash_equals($secret, $sent);

        foreach ([self::SECRET_HEADER, self::LEGACY_HEADER] as $h) {
            $request->headers->remove($h);
            $request->server->remove('HTTP_'.strtoupper(str_replace('-', '_', $h)));
        }
        URL::forceRootUrl(null);

        if ($trusted) {
            $this->applyForwarded($request);
        }
        foreach (self::FORWARDED as $h) {
            $request->headers->remove($h);
            $request->server->remove('HTTP_'.strtoupper(str_replace('-', '_', $h)));
        }

        try {
            return $next($request);
        } finally {
            URL::forceRootUrl(null); // never leak one request's host into the next (workers, tests)
        }
    }

    private function applyForwarded(Request $request): void
    {
        $ip = trim(explode(',', (string) $request->headers->get('X-Forwarded-For', ''))[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $request->server->set('REMOTE_ADDR', $ip);
        }

        $proto = strtolower(trim(explode(',', (string) $request->headers->get('X-Forwarded-Proto', ''))[0]));
        $host = strtolower(trim(explode(',', (string) $request->headers->get('X-Forwarded-Host', ''))[0]));
        if (in_array($proto, ['http', 'https'], true)) {
            $request->server->set('HTTPS', $proto === 'https' ? 'on' : 'off');
        }
        if ($host !== '' && preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $host) === 1) {
            $request->headers->set('Host', $host);
            $request->server->set('HTTP_HOST', $host);
            URL::forceRootUrl(($proto ?: $request->getScheme()).'://'.$host);
        }
    }
}
