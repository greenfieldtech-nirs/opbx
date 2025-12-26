<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures that the authenticated user has an organization context.
 */
class EnsureTenantScope
{
    /**
     * Handle an incoming request.
     *
     * @param \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!isset($user->organization_id)) {
            return response()->json([
                'message' => 'User does not belong to an organization.',
            ], 403);
        }

        if (!$user->organization || !$user->organization->isActive()) {
            return response()->json([
                'message' => 'Organization is not active.',
            ], 403);
        }

        return $next($request);
    }
}
