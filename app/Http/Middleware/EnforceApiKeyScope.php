<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\GrantableResource;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces per-resource read/write scope for API-key-authenticated requests.
 *
 * Runs AFTER auth:sanctum. Requests authenticated by a normal User pass through
 * untouched (their access is governed by roles/policies). For API keys this is
 * the ONLY authorization gate — deny-by-default: a key may only reach a route
 * whose resource it has been granted, and only with a level (read/write) that
 * permits the HTTP method.
 *
 * This must sit ahead of the controllers so a forbidden request is rejected
 * before any controller code (which assumes a User with role methods) runs.
 */
class EnforceApiKeyScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Only API keys are subject to scope enforcement. Users use role checks.
        if (! $user instanceof ApiKey) {
            return $next($request);
        }

        $resource = GrantableResource::fromRouteName($request->route()?->getName());

        if ($resource === null) {
            return response()->json([
                'message' => 'This API key is not permitted to access this endpoint.',
            ], 403);
        }

        $level = $user->levelForResource($resource);

        if ($level === null) {
            return response()->json([
                'message' => "This API key is not permitted to access {$resource->value}.",
            ], 403);
        }

        if (! $level->permitsMethod($request->getMethod())) {
            return response()->json([
                'message' => "This API key has read-only access to {$resource->value}.",
            ], 403);
        }

        return $next($request);
    }
}
