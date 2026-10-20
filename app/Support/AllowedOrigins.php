<?php

namespace App\Support;

/**
 * Origins allowed to call the cookie-authenticated auth endpoints (07 §3.2 CSRF defence; backlog P0.3-BE-01).
 * An origin is trusted when it equals an entry of `ledgerflow.allowed_origins` or matches a pattern of
 * `ledgerflow.allowed_origin_patterns`, where `*` stands for part of one DNS label (Vercel preview URLs such as
 * https://pluto-erp-frontend-git-feat-x-team.vercel.app). Patterns must be https unless the host is local.
 */
class AllowedOrigins
{
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1'];

    public static function allows(string $origin): bool
    {
        $origin = strtolower(rtrim($origin, '/'));
        foreach (config('ledgerflow.allowed_origins') as $exact) {
            if ($origin === strtolower(rtrim($exact, '/'))) {
                return true;
            }
        }
        foreach (config('ledgerflow.allowed_origin_patterns') as $pattern) {
            $regex = self::regex($pattern);
            if ($regex !== null && preg_match($regex, $origin) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Anchored regex for a pattern, or null when the pattern is not acceptable (plain http to a public host). */
    private static function regex(string $pattern): ?string
    {
        $pattern = strtolower(rtrim(trim($pattern), '/'));
        $parts = parse_url(str_replace('*', 'x', $pattern));
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if ($parts['scheme'] !== 'https' && ! ($parts['scheme'] === 'http' && in_array($parts['host'], self::LOCAL_HOSTS, true))) {
            return null;
        }

        return '#^'.str_replace('\*', '[a-z0-9-]+', preg_quote($pattern, '#')).'$#';
    }
}
