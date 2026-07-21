<?php

declare(strict_types=1);

use App\Http\Middleware\BypassOrganizationScope;
use App\Http\Middleware\DialerWorkerAuth;
use App\Http\Middleware\EnforceApiKeyScope;
use App\Http\Middleware\EnsurePlatformManager;
use App\Http\Middleware\EnsureTenantScope;
use App\Http\Middleware\EnsureWebhookIdempotency;
use App\Http\Middleware\RateLimitPerOrganization;
use App\Http\Middleware\RateLimitSensitiveOperations;
use App\Http\Middleware\ResolveApiKey;
use App\Http\Middleware\ResolveEmbedToken;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetImpersonationContext;
use App\Http\Middleware\VerifyCloudonixSignature;
use App\Http\Middleware\VerifyVoiceWebhookAuth;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        $middleware->append(SecurityHeaders::class);

        // Handle CORS for API routes
        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        // Middleware aliases
        $middleware->alias([
            'tenant.scope' => EnsureTenantScope::class,
            'webhook.signature' => VerifyCloudonixSignature::class,
            'webhook.idempotency' => EnsureWebhookIdempotency::class,
            'voice.webhook.auth' => VerifyVoiceWebhookAuth::class,
            'rate_limit_org' => RateLimitPerOrganization::class,
            'sensitive-operations' => RateLimitSensitiveOperations::class,
            'platform.manager' => EnsurePlatformManager::class,
            'bypass.organization.scope' => BypassOrganizationScope::class,
            'impersonation.context' => SetImpersonationContext::class,
            'dialer.worker.auth' => DialerWorkerAuth::class,
            'resolve.api.key' => ResolveApiKey::class,
            'enforce.api.key.scope' => EnforceApiKeyScope::class,
            'resolve.embed.token' => ResolveEmbedToken::class,
        ]);

        // ResolveApiKey must run BEFORE auth:sanctum. Laravel's middleware
        // priority pulls Authenticate (AuthenticatesRequests) to a fixed slot,
        // so listing resolve.api.key first in the route group is not enough —
        // register it ahead of Authenticate in the priority list explicitly.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ResolveApiKey::class,
        );

        // DO NOT add EnforceApiKeyScope to the priority list. It must keep its
        // route-group position, which runs AFTER auth:sanctum (so $request->user()
        // is populated) and BEFORE controllers/model-binding. If it were pulled
        // ahead of Authenticate, user() would be null, every request would fall
        // through its `! instanceof ApiKey` guard, and scope enforcement would be
        // silently bypassed for all keys.

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
            if ($data instanceof Request) {
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
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'Unauthenticated',
                    'message' => 'Authentication required to access this resource.',
                ], 401);
            }
        });

        $exceptions->render(function (AuthorizationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => $e->getMessage() ?: 'You do not have permission to perform this action.',
                ], 403);
            }
        });

        // Handle HTTP exceptions with custom error page
        $exceptions->render(function (HttpExceptionInterface $e, $request) {
            $statusCode = $e->getStatusCode();

            // Only handle specific error codes with the custom view
            if (! in_array($statusCode, [403, 404, 405, 500], true)) {
                return null; // Let Laravel handle other codes
            }

            // Check for query parameter override (for testing)
            $queryCode = $request->query('code');
            if ($queryCode && in_array((int) $queryCode, [403, 404, 405, 500], true)) {
                $statusCode = (int) $queryCode;
            }

            // Only return JSON if the request explicitly expects JSON (via Accept header)
            // Webhook routes and browser requests will get the HTML error page
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => class_basename(get_class($e)),
                    'message' => $e->getMessage() ?: 'An error occurred.',
                    'status' => $statusCode,
                ], $statusCode);
            }

            // Generate CSP nonce for inline scripts
            $nonce = base64_encode(random_bytes(16));

            return response()->view('errors.custom', [
                'code' => $statusCode,
                'csp_nonce' => $nonce,
            ], $statusCode)->withHeaders([
                'Content-Security-Policy' => "script-src 'self' 'nonce-{$nonce}'; style-src 'self' 'unsafe-inline'",
            ]);
        });
    })->create();
