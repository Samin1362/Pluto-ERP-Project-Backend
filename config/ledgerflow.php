<?php

/*
 * LedgerFlow settings. Secrets never live here: keys are read from files outside the repository or from the
 * server environment (07 §12). Defaults are safe for production; local overrides go in .env.
 */
// Key paths may be absolute or relative to the project root; empty means storage/keys/<file>.
$keyPath = fn (string $var, string $file): string => ($p = (string) env($var, '')) === ''
    ? storage_path("keys/{$file}")
    : (str_starts_with($p, '/') ? $p : base_path($p));

return [

    'jwt' => [
        // ES256 key pair (07 §3.1). Generate with `php artisan ledgerflow:jwt-keys`.
        'private_key_path' => $keyPath('JWT_PRIVATE_KEY_PATH', 'jwt-private.pem'),
        'public_key_path' => $keyPath('JWT_PUBLIC_KEY_PATH', 'jwt-public.pem'),
        'key_id' => env('JWT_KEY_ID', 'k1'),
        'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost')),
        'audience' => env('JWT_AUDIENCE', 'ledgerflow-spa'),
        'ttl_seconds' => (int) env('JWT_TTL_SECONDS', 900),          // 15 minutes
        'leeway_seconds' => 30,
    ],

    'refresh' => [
        'cookie' => 'lf_rt',
        'path' => '/api/v1/auth',
        'ttl_days' => (int) env('REFRESH_TTL_DAYS', 14),
        // Secure is required in production. Browsers also accept Secure cookies on http://localhost and 127.0.0.1.
        'secure' => (bool) env('REFRESH_COOKIE_SECURE', true),
    ],

    'login' => [
        'max_failures_per_source' => 5,     // per (e-mail, IP) in the decay window -> 429 (R-12)
        'source_decay_seconds' => 900,
        'max_failures_per_account' => 50,   // per account per hour -> account locked, answers 401 (R-12)
        'account_lock_seconds' => 3600,
    ],

    // 05 §1: requests per minute per IP to /auth/* (login, two-factor, refresh, logout). Every page load refreshes,
    // so an office behind one public IP shares this budget (open item in 15 §6).
    'rate_limits' => [
        'auth_per_ip' => (int) env('AUTH_RATE_LIMIT_PER_IP', 20),
        // 05 §1: authenticated calls per minute per user
        'api_per_user' => (int) env('API_RATE_LIMIT_PER_USER', 120),
    ],

    // 2-step verification (07 §5): the name shown in authenticator apps
    'two_factor' => [
        'issuer' => env('TWO_FACTOR_ISSUER', 'LedgerFlow'),
    ],

    // Shared with the Vercel /api proxy (backlog P0.3-BE-02): requests that carry it may set the client IP and host.
    'proxy' => [
        'shared_secret' => env('PROXY_SHARED_SECRET', ''),
    ],

    // Frontend address used in e-mails and invitations (backlog P0.3-BE-03).
    'frontend_url' => rtrim((string) env('APP_FRONTEND_URL', 'http://localhost:5173'), '/'),

    // Origins allowed to call the cookie-authenticated auth endpoints (07 §3.2 CSRF defence).
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('LEDGERFLOW_ALLOWED_ORIGINS', env('APP_URL', '')))))),
    // Comma-separated patterns where `*` matches part of one DNS label, e.g. Vercel preview URLs (backlog P0.3-BE-01).
    'allowed_origin_patterns' => array_values(array_filter(array_map('trim', explode(',', (string) env('LEDGERFLOW_ALLOWED_ORIGIN_PATTERNS', ''))))),
];
