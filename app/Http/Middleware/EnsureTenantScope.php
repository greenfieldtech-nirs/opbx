<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OrganizationStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures that the authenticated user has an organization context.
 * Also checks if the organization is suspended and blocks access.
 */
class EnsureTenantScope
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Platform managers bypass organization status checks
        // They use the platform.manager middleware instead
        if ($user->is_platform_manager) {
            return $next($request);
        }

        if (! isset($user->organization_id)) {
            return response()->json([
                'message' => 'User does not belong to an organization.',
            ], 403);
        }

        // Check if organization exists
        if (! $user->organization) {
            return response()->json([
                'message' => 'Organization not found.',
            ], 403);
        }

        // Check organization status
        $organizationStatus = $user->organization->status;

        // Block access if organization is suspended
        if ($organizationStatus === OrganizationStatus::SUSPENDED->value) {
            return response()->json([
                'message' => 'Your organization has been suspended.',
            ], 403);
        }

        // Block access if organization is deleted
        if ($organizationStatus === OrganizationStatus::DELETED->value) {
            return response()->json([
                'message' => 'Organization is not active.',
            ], 403);
        }

        // Check legacy isActive() method for backwards compatibility
        if (! $user->organization->isActive()) {
            return response()->json([
                'message' => 'Organization is not active.',
            ], 403);
        }

        return $next($request);
    }
}
