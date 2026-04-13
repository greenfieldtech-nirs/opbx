<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
            \App\Services\CloudonixClient\CloudonixClient::class,
            function ($app) {
                // Instantiate without requiring credentials for ad-hoc validation use cases
                return new \App\Services\CloudonixClient\CloudonixClient(
                    settings: null,
                    requireCredentials: false
                );
            }
        );

        $this->app->singleton(
            \App\Services\CallStateManager\CallStateManager::class
        );

        $this->app->singleton(
            \App\Services\CallRouting\CallRoutingService::class
        );

        $this->app->singleton(
            \App\Services\VoiceRouting\VoiceRoutingCacheService::class
        );

        $this->app->singleton(
            \App\Services\IvrStateService::class
        );

        $this->app->singleton(
            \App\Services\RoutingSentryService::class
        );

        $this->app->singleton(
            \App\Services\Cloudonix\CloudonixVoiceService::class
        );

        // Register Auto Dialer Cloudonix Service
        $this->app->singleton(
            \App\Services\AutoDialer\AutoDialerCloudonixService::class
        );

        $this->app->singleton(
            \App\Services\PasswordGenerator::class
        );

        // Register Voice Routing Strategies
        // Note: QueueRoutingStrategy is reserved for Phase 4+ Call Center features
        $this->app->tag([
            \App\Services\VoiceRouting\Strategies\UserRoutingStrategy::class,
            \App\Services\VoiceRouting\Strategies\RingGroupRoutingStrategy::class,
            \App\Services\VoiceRouting\Strategies\ConferenceRoutingStrategy::class,
            \App\Services\VoiceRouting\Strategies\IvrRoutingStrategy::class,
            \App\Services\VoiceRouting\Strategies\AiAgentRoutingStrategy::class,
            \App\Services\VoiceRouting\Strategies\AiLoadBalancerRoutingStrategy::class,
            \App\Services\VoiceRouting\Strategies\ForwardRoutingStrategy::class,
        ], 'voice_routing.strategies');

        // Register Inbound Blacklist Service
        $this->app->singleton(
            \App\Services\InboundBlacklist\InboundBlacklistService::class
        );

        // Register Email Validation Service
        $this->app->singleton(
            \App\Services\EmailValidation\Contracts\EmailValidatorInterface::class,
            \App\Services\EmailValidation\UserCheckEmailValidator::class
        );

        // Register Voice Routing Manager
        $this->app->singleton(
            \App\Services\VoiceRouting\VoiceRoutingManager::class,
            function ($app) {
                $strategyExecutor = new \App\Services\VoiceRouting\VoiceRoutingStrategyExecutor(
                    $app->tagged('voice_routing.strategies')
                );

                $inboundRouting = new \App\Services\VoiceRouting\InboundRoutingService(
                    $app->make(\App\Services\VoiceRouting\VoiceRoutingCacheService::class),
                    $app->make(\App\Services\VoiceRouting\ExtensionRoutingService::class),
                    $strategyExecutor
                );

                $extensionRouting = new \App\Services\VoiceRouting\ExtensionRoutingService(
                    $app->make(\App\Services\VoiceRouting\VoiceRoutingCacheService::class)
                );

                $ivrRouting = new \App\Services\VoiceRouting\IvrRoutingService(
                    $app->make(\App\Services\IvrStateService::class),
                    $extensionRouting,
                    $strategyExecutor
                );

                $ringGroupRouting = new \App\Services\VoiceRouting\RingGroupRoutingService(
                    $strategyExecutor
                );

                return new \App\Services\VoiceRouting\VoiceRoutingManager(
                    $app->make(\App\Services\VoiceRouting\VoiceRoutingCacheService::class),
                    $app->make(\App\Services\InboundBlacklist\InboundBlacklistService::class),
                    $app->make(\App\Services\VoiceRouting\OutboundRoutingService::class),
                    $app->make(\App\Services\VoiceRouting\BusinessHoursRoutingService::class),
                    $inboundRouting,
                    $extensionRouting,
                    $ivrRouting,
                    $ringGroupRouting,
                    $strategyExecutor
                );
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Production security: Redis password validation
        if ($this->app->environment('production')) {
            $redisPassword = config('database.redis.default.password');

            if (empty($redisPassword)) {
                Log::critical('SECURITY CRITICAL: Redis password not configured in production!', [
                    'message' => 'Redis is running without password protection',
                    'recommendation' => 'Set REDIS_PASSWORD in your .env file',
                    'command' => 'php artisan generate:password',
                    'risk' => 'Unauthorized access to Redis data (sessions, cache, call state, idempotency keys)',
                ]);

                // In production, block startup if Redis password is missing
                // Uncomment the following line to enforce strict security:
                // throw new \RuntimeException('Redis password must be configured in production. Set REDIS_PASSWORD in .env file.');
            }

            // Also validate other critical security settings
            $this->validateSecurityConfiguration();
        }

        // Register model policies
        Gate::policy(\App\Models\Extension::class, \App\Policies\ExtensionPolicy::class);
        Gate::policy(\App\Models\User::class, \App\Policies\UserPolicy::class);
        Gate::policy(\App\Models\ConferenceRoom::class, \App\Policies\ConferenceRoomPolicy::class);
        Gate::policy(\App\Models\CloudonixSettings::class, \App\Policies\CloudonixSettingsPolicy::class);
        Gate::policy(\App\Models\Recording::class, \App\Policies\RecordingPolicy::class);
        Gate::policy(\App\Models\CallDetailRecord::class, \App\Policies\CallDetailRecordPolicy::class);
        Gate::policy(\App\Models\AiAssistant::class, \App\Policies\AiAssistantPolicy::class);
        Gate::policy(\App\Models\AiAssistantLoadBalancer::class, \App\Policies\AiAssistantLoadBalancerPolicy::class);
        Gate::policy(\App\Models\InboundBlacklist::class, \App\Policies\InboundBlacklistPolicy::class);
        Gate::policy(\App\Models\BlockedCallLog::class, \App\Policies\InboundBlacklistPolicy::class);
        Gate::policy(\App\Models\AutoDialerCampaign::class, \App\Policies\AutoDialerCampaignPolicy::class);
        Gate::policy(\App\Models\AutoDialerList::class, \App\Policies\DistributionListPolicy::class);

        // Platform Manager: Route model binding override for platform routes
        // This bypasses OrganizationScope when resolving organization models in platform routes
        \Illuminate\Support\Facades\Route::bind('organization', function (string $value) {
            if (request()->is('api/v1/platform/*')) {
                return \App\Scopes\OrganizationScope::bypass(
                    fn () => \App\Models\Organization::findOrFail($value)
                );
            }

            return \App\Models\Organization::findOrFail($value);
        });

        // Configure rate limiting
        $this->configureRateLimiting();

        // Register model observers for cache invalidation (Phase 1 Step 8)
        \App\Models\Extension::observe(\App\Observers\ExtensionCacheObserver::class);

        // Register consolidated business hours cache observer for all related models
        $businessHoursObserver = app(\App\Observers\BusinessHoursCacheObserver::class);
        \App\Models\BusinessHoursSchedule::observe($businessHoursObserver);
        \App\Models\BusinessHoursScheduleDay::observe($businessHoursObserver);
        \App\Models\BusinessHoursTimeRange::observe($businessHoursObserver);
        \App\Models\BusinessHoursException::observe($businessHoursObserver);

        // Disable model events for CLI commands if needed
        if ($this->app->runningInConsole()) {
            // Add any console-specific bootstrapping here
        }
    }

    /**
     * Validate critical security configuration in production.
     *
     * Logs warnings for missing security settings but does not block startup.
     * For stricter enforcement, uncomment the exception throws.
     */
    protected function validateSecurityConfiguration(): void
    {
        $issues = [];

        // Check Redis password
        if (empty(config('database.redis.default.password'))) {
            $issues[] = 'Redis password not configured';
        }

        // Check APP_KEY is set
        if (empty(config('app.key')) || strlen(config('app.key')) < 32) {
            $issues[] = 'APP_KEY not set or invalid';
        }

        // Check session configuration
        if (config('session.driver') === 'database' && empty(config('session.lifetime'))) {
            $issues[] = 'Session lifetime not configured';
        }

        // Log all security configuration issues
        if (! empty($issues)) {
            Log::warning('Security configuration issues detected', [
                'issues' => $issues,
                'recommendation' => 'Review and fix the security configuration issues listed above',
            ]);
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
                ->by($request->user() ? $request->user()->id : $request->ip())
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
                    $cxml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".
                        '<Response>'."\n".
                        '  <Say language="en-US">Service temporarily unavailable. Rate limit exceeded.</Say>'."\n".
                        '  <Hangup/>'."\n".
                        '</Response>';

                    return response($cxml, 429, array_merge($headers, [
                        'Content-Type' => 'application/xml',
                    ]));
                });
        });

        // Sensitive operations - 10 requests per minute per user
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(config('rate_limiting.sensitive', 10))
                ->by($request->user() ? $request->user()->id : $request->ip())
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

        // Registration - 10 requests per hour per IP to prevent abuse
        RateLimiter::for('registration', function (Request $request) {
            return Limit::perHour(config('rate_limiting.registration', 10))
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'error' => [
                            'code' => 'RATE_LIMIT_EXCEEDED',
                            'message' => 'Too many registration attempts. Please try again in 1 hour.',
                            'retry_after' => $headers['Retry-After'] ?? 3600,
                        ],
                    ], 429, $headers);
                });
        });

        // Dialer Worker API - High rate limit for automated worker processes
        RateLimiter::for('dialer-worker', function (Request $request) {
            return Limit::perMinute(config('services.dialer_worker.rate_limit_per_minute', 1000))
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'error' => 'Too Many Requests',
                        'message' => 'Dialer worker rate limit exceeded.',
                        'retry_after' => $headers['Retry-After'] ?? null,
                    ], 429, $headers);
                });
        });
    }
}
