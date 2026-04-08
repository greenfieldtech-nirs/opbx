<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Middleware to authenticate Dialer Worker API requests.
 *
 * This middleware verifies the Authorization header contains a valid
 * Bearer token matching the configured DIALER_WORKER_API_TOKEN.
 *
 * Supports token rotation: checks both primary and secondary tokens
 * for zero-downtime deployments.
 */
class DialerWorkerAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $authHeader = $request->header('Authorization');

        if (! $authHeader || ! str_starts_with($authHeader, 'Bearer ')) {
            Log::warning('Dialer worker auth failed: Missing or invalid Authorization header', [
                'ip' => $request->ip(),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Missing or invalid Authorization header',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $token = substr($authHeader, 7);
        $validToken = config('services.dialer_worker.token');
        $secondaryToken = config('services.dialer_worker.token_secondary');

        // Token must be configured
        if (empty($validToken)) {
            Log::error('Dialer worker auth failed: Token not configured');

            return response()->json([
                'error' => 'Service Unavailable',
                'message' => 'Worker authentication not configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Check against primary or secondary token (for rotation)
        $isValid = hash_equals($validToken, $token);
        $isSecondaryValid = ! empty($secondaryToken) && hash_equals($secondaryToken, $token);

        if (! $isValid && ! $isSecondaryValid) {
            Log::warning('Dialer worker auth failed: Invalid token', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'token_prefix' => substr($token, 0, 8).'...',
            ]);

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid authentication token',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Log successful authentication with token type
        Log::debug('Dialer worker authenticated', [
            'ip' => $request->ip(),
            'path' => $request->path(),
            'token_type' => $isSecondaryValid ? 'secondary' : 'primary',
        ]);

        return $next($request);
    }
}
