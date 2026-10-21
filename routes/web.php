<?php

use Illuminate\Support\Facades\Route;

/*
 * The React SPA is hosted separately (Vercel, backlog P0.6) and reaches the API through its /api proxy.
 * Laravel only answers the root with a short pointer to the API; /up is registered in bootstrap/app.php.
 */
Route::get('/', fn () => response()->json(['name' => 'Pluto API', 'docs' => '/api/v1']));
