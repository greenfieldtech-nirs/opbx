<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure Platform Manager Middleware
 *
 * Verifies that the authenticated user has the platform manager flag set.
 * Returns 403 Forbidden if the user is not a platform manager.
 */
class EnsurePlatformManager
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_platform_manager) {
            return response()->json([
                'message' => 'Forbidden. Platform manager access required.',
            ], 403);
        }

        return $next($request);
    }
}
