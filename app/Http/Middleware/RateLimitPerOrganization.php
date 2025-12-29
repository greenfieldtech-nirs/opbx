<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Fallback\ResilientCacheService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-organization rate limiting middleware.
 *
 * Prevents a single organization from exhausting system resources
 * by enforcing configurable rate limits per endpoint type.
 */
class RateLimitPerOrganization
{
    private ResilientCacheService $cache;

    public function __construct()
    {
        $this->cache = new ResilientCacheService();
    }

    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     * @param string $limitType The rate limit configuration key (e.g., 'webhook', 'api', 'voice_routing')
     */
    public function handle(Request $request, Closure $next, string $limitType = 'default'): Response
    {
        // Extract organization ID from request
        $organizationId = $this->extractOrganizationId($request);

        if (!$organizationId) {
            Log::warning('Rate limit: Organization not identified', [
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Organization not identified',
                'message' => 'Unable to apply rate limiting',
            ], 400);
        }

        // Get rate limit configuration
        $limits = config("rate-limiting.{$limitType}");

        if (!$limits) {
            Log::error('Rate limit: Invalid limit type', [
                'limit_type' => $limitType,
                'organization_id' => $organizationId,
            ]);

            // Use default limits if config missing
            $limits = config('rate-limiting.default');
        }

        // Fallback to hardcoded defaults if config not loaded (e.g., in tests)
        if (!$limits) {
            $limits = [
                'max_attempts' => 60,
                'per_minutes' => 1,
            ];
        }

        $maxAttempts = (int) $limits['max_attempts'];
        $perMinutes = (int) $limits['per_minutes'];

        // Check rate limit
        $key = "rate_limit:org:{$organizationId}:{$limitType}";
        $attempts = $this->incrementAttempts($key, $perMinutes);

        if ($attempts > $maxAttempts) {
            $this->recordRateLimitExceeded($organizationId, $limitType, $attempts, $maxAttempts);

            return response()->json([
                'error' => 'Rate limit exceeded',
                'message' => "Maximum {$maxAttempts} requests per {$perMinutes} minute(s) allowed",
                'retry_after' => 60,
            ], 429)
            ->header('Retry-After', '60')
            ->header('X-RateLimit-Limit', (string) $maxAttempts)
            ->header('X-RateLimit-Remaining', '0')
            ->header('X-RateLimit-Reset', (string) (time() + 60));
        }

        // Record usage for monitoring
        $this->recordUsage($organizationId, $limitType, $attempts, $maxAttempts);

        // Process request
        $response = $next($request);

        // Add rate limit headers to response
        $remaining = max(0, $maxAttempts - $attempts);
        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Reset', (string) (time() + ($perMinutes * 60)));

        return $response;
    }

    /**
     * Extract organization ID from request.
     */
    private function extractOrganizationId(Request $request): ?int
    {
        // Try to get from middleware-injected parameter (webhooks)
        $orgId = $request->input('_organization_id');

        if ($orgId) {
            return (int) $orgId;
        }

        // Try to get from authenticated user (API requests)
        $user = $request->user();

        if ($user && isset($user->organization_id)) {
            return (int) $user->organization_id;
        }

        // Try to get from header (for testing/debugging)
        $headerOrgId = $request->header('X-Organization-ID');

        if ($headerOrgId) {
            return (int) $headerOrgId;
        }

        return null;
    }

    /**
     * Increment rate limit attempts counter.
     */
    private function incrementAttempts(string $key, int $minutes): int
    {
        // Try to get current value
        $current = Cache::get($key);

        if ($current === null) {
            // First request in window - set initial value with TTL
            Cache::put($key, 1, now()->addMinutes($minutes));

            return 1;
        }

        // Increment counter - reset TTL to full window on each request
        // This is acceptable for rate limiting as it prevents abuse
        $attempts = $current + 1;
        Cache::put($key, $attempts, now()->addMinutes($minutes));

        return $attempts;
    }

    /**
     * Record rate limit exceeded event for monitoring.
     */
    private function recordRateLimitExceeded(
        int $organizationId,
        string $limitType,
        int $attempts,
        int $maxAttempts
    ): void {
        Log::warning('Organization rate limit exceeded', [
            'organization_id' => $organizationId,
            'limit_type' => $limitType,
            'attempts' => $attempts,
            'max_attempts' => $maxAttempts,
            'timestamp' => now()->toIso8601String(),
        ]);

        // TODO: Send to metrics/alerting system (Phase 4)
    }

    /**
     * Record API usage for monitoring.
     */
    private function recordUsage(
        int $organizationId,
        string $limitType,
        int $attempts,
        int $maxAttempts
    ): void {
        // Only log when approaching limit (80%+) to reduce noise
        if ($attempts >= ($maxAttempts * 0.8)) {
            Log::info('Organization approaching rate limit', [
                'organization_id' => $organizationId,
                'limit_type' => $limitType,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'remaining' => $maxAttempts - $attempts,
                'timestamp' => now()->toIso8601String(),
            ]);
        }

        // TODO: Send usage metrics to monitoring system (Phase 4)
    }
}
