<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ForcePasswordChange;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Allow the Vite dev server to use Sanctum's stateful SPA cookies.
        $middleware->statefulApi();

        // The frontend Nginx proxy sends the backend Host upstream so Render
        // routes correctly, and the browser's host in X-Forwarded-Host.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Laravel does not enable API throttling by default.
        $middleware->api(append: [ThrottleRequests::class.':api']);

        $middleware->alias([
            'role' => EnsureRole::class,
            'must-change-password' => ForcePasswordChange::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Unauthenticated.',
                    'code' => 'UNAUTHENTICATED',
                ], 401);
            }
        });

        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $exception->getMessage() ?: 'Request failed.',
                    'code' => 'HTTP_EXCEPTION',
                ], $exception->getStatusCode(), $exception->getHeaders());
            }
        });
    })->create();
