<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\AcceptXIfMatch;
use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\ForceJsonApi;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveCompany;
use App\Http\Middleware\TrustLedgerflowProxy;
use App\Support\Problem\ApiProblem;
use App\Support\Problem\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Liveness and readiness probes: outside /api/v1, no auth, no throttle (backlog P0.2-BE-06).
            Route::get('api/health', [HealthController::class, 'health']);
            Route::get('api/ready', [HealthController::class, 'ready']);
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Our own proxy (Vercel -> tunnel) is identified by a shared secret, never by IP (backlog P0.3-BE-02).
        $middleware->prepend(TrustLedgerflowProxy::class);
        // 07 §4 order: request id and JSON handling first, then authentication, company, permission.
        $middleware->api(prepend: [RequestId::class, ForceJsonApi::class, AcceptXIfMatch::class]);
        $middleware->alias([
            'jwt' => AuthenticateJwt::class,
            'company' => ResolveCompany::class,
            'perm' => RequirePermission::class,
            'idempotent' => Idempotency::class,
        ]);
        // Route middleware runs in the order it is declared in routes/api.php (jwt -> company -> perm -> idempotent).
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every API error is RFC 9457 application/problem+json with a stable `code` (05 §3).
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(fn (Throwable $e, $request) => $request->is('api/*') ? ProblemRenderer::render($e, $request) : null);
        $exceptions->dontReport([ApiProblem::class]);
    })->create();
