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
    /**
     * Maximum attempts per minute for sensitive operations.
     */
    private const MAX_ATTEMPTS = 5;

    /**
     * Decay period in seconds.
     */
    private const DECAY_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        // Rate limit by user + IP combination
        $userId = $request->user()?->id ?? 'anonymous';
        $key = "sensitive-operation:{$userId}:{$request->ip()}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            Log::warning('Rate limit exceeded for sensitive operation', [
                'route' => $request->route()?->getName(),
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'attempts' => RateLimiter::attempts($key),
            ]);

            return response()->json([
                'error' => 'Too many requests',
                'message' => 'Please wait before trying again.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, self::DECAY_SECONDS);

        return $next($request);
    }
}