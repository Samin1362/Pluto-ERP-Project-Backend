<?php

use Illuminate\Support\Facades\Route;

/*
 * Serves the built React SPA (frontend/ -> public/app) for every non-API path, so client-side routes like
 * /customers/12 load the app. In production the SPA may be served from its own origin instead (07 §3.2).
 */
Route::get('/{path?}', function () {
    $index = public_path('app/index.html');
    abort_unless(is_file($index), 404, 'Frontend not built: run `npm run build` in frontend/.');

    return response()->file($index, ['Cache-Control' => 'no-cache', 'Content-Type' => 'text/html; charset=utf-8']);
})->where('path', '^(?!api/|up$|app/).*');
