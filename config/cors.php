<?php

/*
 * CORS (P17.1-BE-03, ZAP baseline TC-NFR-009). Browsers reach the API same-origin — through the Vercel /api proxy live
 * and the Vite proxy locally — so no wildcard: only the configured app origins (PLUTO_ALLOWED_ORIGINS and the Vercel
 * preview patterns in PLUTO_ALLOWED_ORIGIN_PATTERNS) may call it cross-origin. No credentials cross-origin.
 */
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('PLUTO_ALLOWED_ORIGINS', env('APP_URL', ''))))));
$patterns = array_map(
    fn (string $p) => '#^'.str_replace('\*', '[a-z0-9-]+', preg_quote($p, '#')).'$#i',
    array_values(array_filter(array_map('trim', explode(',', (string) env('PLUTO_ALLOWED_ORIGIN_PATTERNS', ''))))),
);

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $origins,
    'allowed_origins_patterns' => $patterns,
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Company-Id', 'Idempotency-Key', 'If-Match', 'X-If-Match', 'X-Request-Id', 'X-Requested-With'],
    'exposed_headers' => ['ETag', 'X-Request-Id', 'Retry-After', 'Idempotency-Replayed'],
    'max_age' => 600,
    'supports_credentials' => false,
];
