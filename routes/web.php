<?php

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * The React SPA is hosted separately (Vercel, backlog P0.6) and reaches the API through its /api proxy.
 * Laravel only answers the root with a short pointer to the API; /up is registered in bootstrap/app.php.
 */
// No session or CSRF cookie: nothing here needs one (ZAP baseline, P17.1-BE-03).
Route::get('/', fn () => response()->json(['name' => 'Pluto API', 'docs' => '/api/v1']))->withoutMiddleware([
    EncryptCookies::class, AddQueuedCookiesToResponse::class,
    StartSession::class, ShareErrorsFromSession::class,
    ValidateCsrfToken::class,
]);
