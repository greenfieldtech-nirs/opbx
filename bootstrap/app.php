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
            'voice.webhook.auth' => \App\Http\Middleware\VerifyVoiceWebhookAuth::class,
            'rate_limit_org' => \App\Http\Middleware\RateLimitPerOrganization::class,
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
        // Prevent sensitive request data from being logged in exceptions
        // These keys will be masked with ******* in logs
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'new_password',
            'new_password_confirmation',
        ]);

        // Hide sensitive headers and input from exception context
        // This prevents them from appearing in error logs and reports
        $exceptions->stopIgnoring([]);
        $exceptions->context(function ($data) {
            $context = [];

            // Add safe request data to context
            if ($data instanceof \Illuminate\Http\Request) {
                $context['url'] = $data->fullUrl();
                $context['method'] = $data->method();
                $context['ip'] = $data->ip();

                // Sanitize input - remove sensitive keys
                $input = $data->except([
                    'password',
                    'password_confirmation',
                    'current_password',
                    'new_password',
                    'new_password_confirmation',
                    'token',
                    'access_token',
                    'api_key',
                    'api_token',
                    'secret',
                    'webhook_secret',
                    'sip_password',
                    'domain_api_key',
                    'domain_requests_api_key',
                    'domain_cdr_auth_key',
                ]);

                if (! empty($input)) {
                    $context['input'] = $input;
                }
            }

            return $context;
        });

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
