<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerList;
use App\Models\BlockedCallLog;
use App\Models\BusinessHoursException;
use App\Models\BusinessHoursSchedule;
use App\Models\BusinessHoursScheduleDay;
use App\Models\BusinessHoursTimeRange;
use App\Models\CallDetailRecord;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CloudonixSettings;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\InboundBlacklist;
use App\Models\Organization;
use App\Models\Recording;
use App\Models\User;
use App\Observers\BusinessHoursCacheObserver;
use App\Observers\ExtensionCacheObserver;
use App\Policies\AiAssistantLoadBalancerPolicy;
use App\Policies\AiAssistantPolicy;
use App\Policies\AutoDialerCampaignPolicy;
use App\Policies\CallDetailRecordPolicy;
use App\Policies\CallTrackingCampaignPolicy;
use App\Policies\CallTrackingNumberPolicy;
use App\Policies\CloudonixSettingsPolicy;
use App\Policies\ConferenceRoomPolicy;
use App\Policies\DistributionListPolicy;
use App\Policies\ExtensionPolicy;
use App\Policies\InboundBlacklistPolicy;
use App\Policies\RecordingPolicy;
use App\Policies\UserPolicy;
use App\Scopes\OrganizationScope;
use App\Services\AutoDialer\AutoDialerCloudonixService;
use App\Services\CallRouting\CallRoutingService;
use App\Services\CallStateManager\CallStateManager;
use App\Services\CallTracking\CallTrackingDestinationResolver;
use App\Services\Cloudonix\CloudonixVoiceService;
use App\Services\CloudonixClient\CloudonixClient;
use App\Services\EmailValidation\Contracts\EmailValidatorInterface;
use App\Services\EmailValidation\UserCheckEmailValidator;
use App\Services\InboundBlacklist\InboundBlacklistService;
use App\Services\IvrStateService;
use App\Services\PasswordGenerator;
use App\Services\RoutingSentryService;
use App\Services\VoiceRouting\BusinessHoursRoutingService;
use App\Services\VoiceRouting\ExtensionRoutingService;
use App\Services\VoiceRouting\InboundRoutingService;
use App\Services\VoiceRouting\IvrRoutingService;
use App\Services\VoiceRouting\OutboundRoutingService;
use App\Services\VoiceRouting\RingGroupRoutingService;
use App\Services\VoiceRouting\Strategies\AiAgentRoutingStrategy;
use App\Services\VoiceRouting\Strategies\AiLoadBalancerRoutingStrategy;
use App\Services\VoiceRouting\Strategies\CallTrackingRoutingStrategy;
use App\Services\VoiceRouting\Strategies\ConferenceRoutingStrategy;
use App\Services\VoiceRouting\Strategies\ForwardRoutingStrategy;
use App\Services\VoiceRouting\Strategies\IvrRoutingStrategy;
use App\Services\VoiceRouting\Strategies\RingGroupRoutingStrategy;
use App\Services\VoiceRouting\Strategies\UserRoutingStrategy;
use App\Services\VoiceRouting\VoiceRoutingCacheService;
use App\Services\VoiceRouting\VoiceRoutingManager;
use App\Services\VoiceRouting\VoiceRoutingStrategyExecutor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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
            CloudonixClient::class,
            function ($app) {
                // Instantiate without requiring credentials for ad-hoc validation use cases
                return new CloudonixClient(
                    settings: null,
                    requireCredentials: false
                );
            }
        );

        $this->app->singleton(
            CallStateManager::class
        );

        $this->app->singleton(
            CallRoutingService::class
        );

        $this->app->singleton(
            VoiceRoutingCacheService::class
        );

        $this->app->singleton(
            IvrStateService::class
        );

        $this->app->singleton(
            RoutingSentryService::class
        );

        $this->app->singleton(
            CloudonixVoiceService::class
        );

        // Register Auto Dialer Cloudonix Service
        $this->app->singleton(
            AutoDialerCloudonixService::class
        );

        $this->app->singleton(
            PasswordGenerator::class
        );

        // Register Voice Routing Strategies
        // Note: QueueRoutingStrategy is reserved for Phase 4+ Call Center features
        $this->app->tag([
            UserRoutingStrategy::class,
            RingGroupRoutingStrategy::class,
            ConferenceRoutingStrategy::class,
            IvrRoutingStrategy::class,
            AiAgentRoutingStrategy::class,
            AiLoadBalancerRoutingStrategy::class,
            ForwardRoutingStrategy::class,
            CallTrackingRoutingStrategy::class,
        ], 'voice_routing.strategies');

        // Register Inbound Blacklist Service
        $this->app->singleton(
            InboundBlacklistService::class
        );

        // Register Email Validation Service
        $this->app->singleton(
            EmailValidatorInterface::class,
            UserCheckEmailValidator::class
        );

        // Register Voice Routing Manager
        $this->app->singleton(
            VoiceRoutingManager::class,
            function ($app) {
                $strategyExecutor = new VoiceRoutingStrategyExecutor(
                    $app->tagged('voice_routing.strategies')
                );

                $callTrackingStrategy = new CallTrackingRoutingStrategy(
                    $strategyExecutor,
                    new CallTrackingDestinationResolver
                );

                $inboundRouting = new InboundRoutingService(
                    $app->make(VoiceRoutingCacheService::class),
                    $app->make(ExtensionRoutingService::class),
                    $strategyExecutor,
                    $callTrackingStrategy
                );

                $extensionRouting = new ExtensionRoutingService(
                    $app->make(VoiceRoutingCacheService::class)
                );

                $ivrRouting = new IvrRoutingService(
                    $app->make(IvrStateService::class),
                    $extensionRouting,
                    $strategyExecutor
                );

                $ringGroupRouting = new RingGroupRoutingService(
                    $strategyExecutor
                );

                return new VoiceRoutingManager(
                    $app->make(VoiceRoutingCacheService::class),
                    $app->make(InboundBlacklistService::class),
                    $app->make(OutboundRoutingService::class),
                    $app->make(BusinessHoursRoutingService::class),
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
        Gate::policy(Extension::class, ExtensionPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(ConferenceRoom::class, ConferenceRoomPolicy::class);
        Gate::policy(CloudonixSettings::class, CloudonixSettingsPolicy::class);
        Gate::policy(Recording::class, RecordingPolicy::class);
        Gate::policy(CallDetailRecord::class, CallDetailRecordPolicy::class);
        Gate::policy(CallTrackingCampaign::class, CallTrackingCampaignPolicy::class);
        Gate::policy(CallTrackingNumber::class, CallTrackingNumberPolicy::class);
        Gate::policy(AiAssistant::class, AiAssistantPolicy::class);
        Gate::policy(AiAssistantLoadBalancer::class, AiAssistantLoadBalancerPolicy::class);
        Gate::policy(InboundBlacklist::class, InboundBlacklistPolicy::class);
        Gate::policy(BlockedCallLog::class, InboundBlacklistPolicy::class);
        Gate::policy(AutoDialerCampaign::class, AutoDialerCampaignPolicy::class);
        Gate::policy(AutoDialerList::class, DistributionListPolicy::class);

        // Platform Manager: Route model binding override for platform routes
        // This bypasses OrganizationScope when resolving organization models in platform routes
        Route::bind('organization', function (string $value) {
            if (request()->is('api/v1/platform/*')) {
                return OrganizationScope::bypass(
                    fn () => Organization::findOrFail($value)
                );
            }

            return Organization::findOrFail($value);
        });

        // Configure rate limiting
        $this->configureRateLimiting();

        // Register model observers for cache invalidation (Phase 1 Step 8)
        Extension::observe(ExtensionCacheObserver::class);

        // Register consolidated business hours cache observer for all related models
        $businessHoursObserver = app(BusinessHoursCacheObserver::class);
        BusinessHoursSchedule::observe($businessHoursObserver);
        BusinessHoursScheduleDay::observe($businessHoursObserver);
        BusinessHoursTimeRange::observe($businessHoursObserver);
        BusinessHoursException::observe($businessHoursObserver);

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
