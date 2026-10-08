<?php

use App\Domain\Shared\DomainException;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\RequireAccess;
use App\Http\Middleware\ResolveContext;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'request.id' => AssignRequestId::class,
            'context' => ResolveContext::class,
            'access' => RequireAccess::class,
        ]);

        // Route-model binding goes through the tenant scope, so the tenant context (and the access check)
        // must run before SubstituteBindings; otherwise every tenant-scoped binding fails closed with 404.
        $middleware->appendToPriorityList(AuthenticatesSessions::class, ResolveContext::class);
        $middleware->appendToPriorityList(ResolveContext::class, RequireAccess::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Business-rule refusals carry a stable machine-readable code.
        $exceptions->render(fn (DomainException $e) => response()->json(
            ['message' => $e->getMessage(), 'code' => $e->errorCode, 'details' => (object) $e->details],
            $e->status,
        ));
    })->create();
