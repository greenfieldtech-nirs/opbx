<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting middleware for sensitive operations.
 *
 * Limits the number of requests a user can make within a given time period.
 * Particularly important for operations like password reset and settings changes.
 */
class RateLimitSensitiveOperations
{
    /**
     * Handle incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip rate limiting for authenticated admin users
        if ($request->user()?->isAdmin()) {
            return $next($request);
        }

        // Define sensitive operations
        $sensitiveRoutes = [
            'extensions/password/reset',
            'extensions/settings',
            'users/settings',
            'users/profile',
        ];

        // Check if current route is sensitive
        $currentRoute = $request->route() ? $request->route()->getName() : null;
        $isSensitiveRoute = $currentRoute && in_array($currentRoute, $sensitiveRoutes);

        if ($isSensitiveRoute) {
            // Rate limit to 5 requests per minute
            $key = 'sensitive-operation:' . ($request->user()?->id : 'anonymous') . ':' . $request->ip();

            if (RateLimiter::tooManyAttempts($key, $maxAttempts = 5, $decayMinutes = 1)) {
                Log::warning('Rate limit exceeded for sensitive operation', [
                    'route' => $currentRoute,
                    'user_id' => $request->user()?->id,
                    'ip' => $request->ip(),
                    'attempts' => RateLimiter::attempts($key),
                ]);

                return response()->json([
                    'error' => 'Too many requests',
                    'message' => 'Please wait before trying again. Rate limit: 5 requests per minute.',
                ], 429);
            }
        }

        return $next($request);
    }

    /**
     * Get maximum number of attempts.
     */
    protected function getMaxAttempts(int $maxAttempts): int
    {
        return $maxAttempts;
    }
}