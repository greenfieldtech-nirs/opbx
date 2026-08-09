<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\OrganizationStatus;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply "Operate As Organization" (platform-owner impersonation).
 *
 * SECURITY MODEL
 * --------------
 * This middleware lets a platform manager (`users.is_platform_manager = true`)
 * temporarily act as the OWNER of a target organization by sending the
 * `X-Operate-As-Organization: <org id>` request header. It works by swapping
 * the resolved auth principal for an EFFECTIVE, in-memory-only User instance
 * whose `organization_id` points at the target org. Because
 * {@see OrganizationScope::getOrganizationId()} reads only
 * `auth()->user()->organization_id`, both DB tenant scoping and policies follow
 * automatically once the effective user is seeded onto the guard.
 *
 * Guarantees:
 * - Only a real, authenticated {@see User} that `is_platform_manager` may
 *   trigger a scope switch. An {@see \App\Models\ApiKey} principal or any
 *   non-platform-manager user sending the header receives a 403 and NO switch
 *   ever happens.
 * - The effective user is a SEPARATE model instance loaded fresh from the
 *   database; its org/role overrides live only in memory. This middleware never
 *   calls save() on it, so the platform owner's real row is never mutated.
 * - The bare header (without a platform-manager token) is inert.
 *
 * Placement: registered in the main authenticated API group AFTER `tenant.scope`
 * and BEFORE `rate_limit_org:api`, so the real user has already been resolved
 * and status-checked before we swap in the effective user.
 */
class ApplyOperateAsOrganization
{
    /**
     * Request header carrying the target organization id.
     */
    public const HEADER = 'X-Operate-As-Organization';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $targetOrgId = $request->header(self::HEADER);

        // No header -> normal request, pass through unchanged.
        if ($targetOrgId === null || $targetOrgId === '') {
            return $next($request);
        }

        $realUser = $request->user();

        // Only a real platform-manager User may switch scope. An ApiKey principal
        // or a non-platform-manager user must never trigger a switch.
        if (! $realUser instanceof User || ! $realUser->is_platform_manager) {
            return response()->json([
                'error' => [
                    'code' => 'OPERATE_AS_FORBIDDEN',
                    'message' => 'Only platform managers may operate as an organization.',
                ],
            ], 403);
        }

        // Resolve the target organization cross-tenant (bypass the global scope).
        $targetOrg = OrganizationScope::bypass(
            fn (): ?Organization => Organization::find((int) $targetOrgId)
        );

        if ($targetOrg === null) {
            return response()->json([
                'error' => [
                    'code' => 'OPERATE_AS_ORG_NOT_FOUND',
                    'message' => 'The target organization could not be found.',
                ],
            ], 404);
        }

        // Only ACTIVE organizations may be targeted. Mirror EnsureTenantScope's
        // positive, deny-by-default check (status === active AND isActive()) so
        // the two never drift if new statuses are added.
        if ($targetOrg->status !== OrganizationStatus::ACTIVE->value || ! $targetOrg->isActive()) {
            return response()->json([
                'error' => [
                    'code' => 'OPERATE_AS_ORG_INACTIVE',
                    'message' => 'The target organization is not active.',
                ],
            ], 403);
        }

        // Build the EFFECTIVE user as a SEPARATE in-memory instance loaded fresh
        // from the DB. We keep the real user id (so tokens/audit still resolve)
        // but override org/role IN MEMORY ONLY. This instance is never persisted.
        $effective = OrganizationScope::bypass(
            fn (): User => User::withoutGlobalScope(OrganizationScope::class)
                ->findOrFail($realUser->id)
        );

        $effective->organization_id = $targetOrg->id;
        $effective->role = UserRole::OWNER;
        $effective->is_platform_manager = false;
        $effective->setRelation('organization', $targetOrg);

        // Mark the instance so it can never be persisted (the User model's
        // saving() guard throws if this instance is saved). This protects the
        // real platform owner's row from any self-mutating write path (e.g.
        // PUT /profile, PUT /profile/password) reached while operating-as.
        $effective->isOperateAsEffective = true;

        // Re-seed the resolved principal on EVERY layer that can report the
        // current user, so both `request()->user()` (used by controllers and
        // EnsureTenantScope) AND `auth()->user()` (used by OrganizationScope)
        // return the effective user. This is the critical bit: auth:sanctum
        // authenticates via the sanctum guard, so seeding only the `web` guard
        // leaves `auth()->user()` reporting the real platform-owner org while
        // `request()->user()` reports the target org -> scope/controller drift
        // and cross-tenant leakage.
        $request->setUserResolver(fn (): User => $effective);

        // Seed both the configured sanctum guard(s) and the default auth guard.
        $guards = array_unique(array_merge(
            Arr::wrap(config('sanctum.guard', 'web')),
            [config('auth.defaults.guard', 'web'), 'sanctum'],
        ));

        foreach ($guards as $guard) {
            Auth::guard($guard)->setUser($effective);
        }

        // Force the default guard resolver (what the global `auth()` helper uses)
        // to return the effective user for the remainder of the request.
        Auth::setUser($effective);

        // Attribution/telemetry: keep the real platform-manager id available so
        // any audit writes during the session remain traceable.
        $request->attributes->set('operate_as_active', true);
        $request->attributes->set('operate_as_real_user_id', $realUser->id);
        $request->attributes->set('operate_as_organization_id', $targetOrg->id);

        return $next($request);
    }
}
