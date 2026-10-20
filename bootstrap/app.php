<?php

use App\Http\Middleware\AuthenticateJwt;
use App\Http\Middleware\ForceJsonApi;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\RequestId;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\ResolveCompany;
use App\Support\Problem\ProblemRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 07 §4 order: request id and JSON handling first, then authentication, company, permission.
        $middleware->api(prepend: [RequestId::class, ForceJsonApi::class]);
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
        $exceptions->dontReport([App\Support\Problem\ApiProblem::class]);
    })->create();
