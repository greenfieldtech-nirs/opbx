<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Services\PlatformAuditService;
use App\Support\TokenAbilities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform Impersonation Controller
 *
 * Lets a platform manager mint a short-lived, organization-scoped token to act
 * as a full owner-role admin inside a target organization ("open as admin"),
 * and to revoke that session ("exit").
 *
 * The minted token authenticates as the platform manager's own user (identity
 * preserved for audit), but is stamped with the target organization id so that
 * SetImpersonationContext + OrganizationScope scope all requests to that org.
 */
class PlatformImpersonationController extends Controller
{
    /**
     * Impersonation token lifetime in minutes.
     */
    private const IMPERSONATION_TTL_MINUTES = 60;

    /**
     * Start impersonating an organization: mint a scoped token.
     */
    public function start(
        Request $request,
        Organization $organization,
        PlatformAuditService $auditService,
    ): JsonResponse {
        // Only active organizations may be impersonated.
        if (! $organization->isActive()) {
            return response()->json([
                'error' => 'ImpersonationUnavailable',
                'message' => 'This organization is not active and cannot be managed.',
            ], 422);
        }

        $user = $request->user();
        $ttlMinutes = (int) config('impersonation.ttl_minutes', self::IMPERSONATION_TTL_MINUTES);

        // Mint a dedicated token on the platform manager's own user. Using a
        // distinct name (not the login flow) so the manager's platform token is
        // never revoked.
        $newToken = $user->createToken(
            "impersonation:org:{$organization->id}",
            TokenAbilities::owner(),
            now()->addMinutes($ttlMinutes),
        );

        /** @var PersonalAccessToken $token */
        $token = $newToken->accessToken;
        $token->forceFill(['impersonated_organization_id' => $organization->id])->save();

        $auditService->log(
            request: $request,
            action: 'organization.impersonation.started',
            targetOrganizationId: $organization->id,
            targetEntityType: 'Organization',
            targetEntityId: $organization->id,
            afterState: [
                'token_id' => $token->id,
                'expires_at' => $token->expires_at?->toIso8601String(),
            ],
            reason: $request->input('reason'),
        );

        return response()->json([
            'access_token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
            'expires_in' => $ttlMinutes * 60,
            'impersonating' => true,
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
            ],
        ]);
    }

    /**
     * Stop the current impersonation session: revoke the impersonation token.
     *
     * This endpoint is called with the impersonation token itself.
     */
    public function stop(
        Request $request,
        PlatformAuditService $auditService,
    ): JsonResponse {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken || ! $token->isImpersonation()) {
            return response()->json([
                'error' => 'NotImpersonating',
                'message' => 'The current session is not an impersonation session.',
            ], 422);
        }

        $organizationId = $token->impersonatedOrganizationId();

        $auditService->log(
            request: $request,
            action: 'organization.impersonation.ended',
            targetOrganizationId: $organizationId,
            targetEntityType: 'Organization',
            targetEntityId: $organizationId,
            beforeState: [
                'token_id' => $token->id,
            ],
        );

        // Revoke the impersonation token.
        $token->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Impersonation session ended.',
        ]);
    }
}
