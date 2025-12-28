<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register service singletons
        $this->app->singleton(
            \App\Services\CloudonixClient\CloudonixClient::class
        );

        $this->app->singleton(
            \App\Services\CallStateManager\CallStateManager::class
        );

        $this->app->singleton(
            \App\Services\CallRouting\CallRoutingService::class
        );

        $this->app->singleton(
            \App\Services\CloudonixApiClient::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register model policies
        Gate::policy(\App\Models\Extension::class, \App\Policies\ExtensionPolicy::class);
        Gate::policy(\App\Models\User::class, \App\Policies\UserPolicy::class);
        Gate::policy(\App\Models\ConferenceRoom::class, \App\Policies\ConferenceRoomPolicy::class);
        Gate::policy(\App\Models\CloudonixSettings::class, \App\Policies\CloudonixSettingsPolicy::class);

        // Configure rate limiting
        $this->configureRateLimiting();

        // Disable model events for CLI commands if needed
        if ($this->app->runningInConsole()) {
            // Add any console-specific bootstrapping here
        }
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // API routes - 60 requests per minute for authenticated users
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(config('rate_limiting.api', 60))
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'error' => 'Too Many Requests',
                        'message' => 'Rate limit exceeded. Please try again later.',
                        'retry_after' => $headers['Retry-After'] ?? null,
                    ], 429, $headers);
                });
        });

        // Webhook routes - 100 requests per minute per IP
        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(config('rate_limiting.webhooks', 100))
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'error' => 'Too Many Requests',
                        'message' => 'Webhook rate limit exceeded.',
                        'retry_after' => $headers['Retry-After'] ?? null,
                    ], 429, $headers);
                });
        });

        // Voice routing routes - 1000 requests per minute per IP (high traffic)
        RateLimiter::for('voice', function (Request $request) {
            return Limit::perMinute(config('rate_limiting.voice', 1000))
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    // Return CXML format for voice routing endpoints
                    $cxml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                        '<Response>' . "\n" .
                        '  <Say language="en-US">Service temporarily unavailable. Rate limit exceeded.</Say>' . "\n" .
                        '  <Hangup/>' . "\n" .
                        '</Response>';

                    return response($cxml, 429, array_merge($headers, [
                        'Content-Type' => 'application/xml',
                    ]));
                });
        });

        // Sensitive operations - 10 requests per minute per user
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(config('rate_limiting.sensitive', 10))
                ->by($request->user()?->id ?: $request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'error' => 'Too Many Requests',
                        'message' => 'Too many attempts. Please try again later.',
                        'retry_after' => $headers['Retry-After'] ?? null,
                    ], 429, $headers);
                });
        });

        // Auth routes - 5 requests per minute per IP for login attempts
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(config('rate_limiting.auth', 5))
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'error' => 'Too Many Requests',
                        'message' => 'Too many login attempts. Please try again later.',
                        'retry_after' => $headers['Retry-After'] ?? null,
                    ], 429, $headers);
                });
        });
    }
}
