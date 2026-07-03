<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Scopes\OrganizationScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bypass Organization Scope Middleware
 *
 * Runs the entire request with the OrganizationScope global scope disabled.
 * Intended for platform management routes where the authenticated user is a
 * platform manager and needs cross-tenant access.
 */
class BypassOrganizationScope
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return OrganizationScope::bypass(function () use ($request, $next): Response {
            return $next($request);
        });
    }
}
