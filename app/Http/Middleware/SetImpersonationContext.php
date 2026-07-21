<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Scopes\OrganizationScope;
use App\Support\ImpersonationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Set Impersonation Context
 *
 * When the authenticated request is made with an impersonation token (a Sanctum
 * token carrying `impersonated_organization_id`), this middleware:
 *   1. Verifies the target organization still exists and is active.
 *   2. Publishes the target organization id into ImpersonationContext so that
 *      OrganizationScope scopes all subsequent queries to that organization.
 *
 * The context is always cleared after the request to avoid leaking across
 * requests in long-running workers.
 *
 * This middleware must run AFTER auth:sanctum (so the token is resolved) and
 * BEFORE tenant.scope / controllers / model binding. It is only mounted on the
 * normal (own-org) API groups — never on platform routes, which run under a
 * full scope bypass.
 */
class SetImpersonationContext
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Defensively reset any impersonation state from a prior request that may
        // linger in shared static memory (e.g. long-running workers, or the test
        // process which reuses the application instance across requests).
        ImpersonationContext::clear();

        $user = $request->user();

        // Only Sanctum-authenticated users expose currentAccessToken(). Other
        // authenticators (e.g. API keys, embed tokens) resolve a different model
        // that has no access token — impersonation never applies to them.
        $token = is_object($user) && method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        if ($token instanceof PersonalAccessToken && $token->isImpersonation()) {
            $organizationId = $token->impersonatedOrganizationId();

            // Verify the impersonated organization still exists and is active.
            // Bypass the scope for this lookup since no tenant context exists yet.
            $organization = OrganizationScope::bypass(
                fn () => Organization::find($organizationId)
            );

            if (! $organization || ! $organization->isActive()) {
                return response()->json([
                    'error' => 'ImpersonationUnavailable',
                    'message' => 'The impersonated organization is no longer available.',
                ], 403);
            }

            // Publish the target org to the scope override.
            ImpersonationContext::set($organizationId);

            // Override the authenticated user's in-memory organization_id for the
            // duration of this request. This is NEVER persisted (no save()). It
            // ensures that not only OrganizationScope but also the many controllers
            // that read $user->organization_id directly (e.g. AbstractApiCrudController
            // for writes/ownership checks) resolve to the impersonated organization.
            // The user's identity (id) is preserved, so audit trails still attribute
            // actions to the real platform manager.
            $originalOrganizationId = $user->organization_id;
            $user->setAttribute('organization_id', $organizationId);
            // Keep Eloquent from treating this as a pending change to be saved.
            $user->syncOriginalAttribute('organization_id');
        }

        try {
            return $next($request);
        } finally {
            ImpersonationContext::clear();

            // Restore the user's original organization_id in-memory (defensive;
            // the model is request-scoped, but avoids surprises in shared state).
            if (isset($originalOrganizationId)) {
                $user->setAttribute('organization_id', $originalOrganizationId);
                $user->syncOriginalAttribute('organization_id');
            }
        }
    }
}
