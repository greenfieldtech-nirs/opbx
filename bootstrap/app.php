<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function (): void {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global middleware for security headers
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Handle CORS for API routes
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Middleware aliases
        $middleware->alias([
            'tenant.scope' => \App\Http\Middleware\EnsureTenantScope::class,
            'webhook.signature' => \App\Http\Middleware\VerifyCloudonixSignature::class,
            'webhook.idempotency' => \App\Http\Middleware\EnsureWebhookIdempotency::class,
        ]);

        // Configure authentication to return JSON for API routes instead of redirecting
        $middleware->redirectGuestsTo(function ($request) {
            // For API routes, always return JSON 401 instead of redirecting
            if ($request->is('api/*') || $request->expectsJson()) {
                return null; // Return 401 with JSON instead of redirect
            }
            // For web routes, redirect to home (no login page in this API-first app)
            return '/';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle authorization exceptions for API routes
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'Authentication required to access this resource.',
                ], 401);
            }
        });

        $exceptions->render(function (\Illuminate\Auth\Access\AuthorizationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => $e->getMessage() ?: 'You do not have permission to perform this action.',
                ], 403);
            }
        });
    })->create();
