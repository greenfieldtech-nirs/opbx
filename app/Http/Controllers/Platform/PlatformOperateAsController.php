<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Platform Operate As Controller
 *
 * Provides the start/stop endpoints for the "Operate As Organization"
 * (platform-owner impersonation) feature. These endpoints DO NOT themselves
 * change server-side scope; the actual context switch is driven by the
 * `X-Operate-As-Organization` header on subsequent requests (see
 * {@see \App\Http\Middleware\ApplyOperateAsOrganization}). Their responsibility
 * is to validate the target organization and to write the audit trail.
 */
class PlatformOperateAsController extends Controller
{
    public function __construct(private readonly PlatformAuditService $auditService) {}

    /**
     * Begin operating as the given organization.
     *
     * Validates the target organization, records an `operate_as.started` audit
     * log entry, and returns the organization summary the SPA needs to render
     * the impersonation banner.
     */
    public function start(Request $request, Organization $organization): JsonResponse
    {
        // Guard against operating as a suspended or deleted organization,
        // mirroring the middleware's error codes.
        if (in_array($organization->status, [
            OrganizationStatus::SUSPENDED->value,
            OrganizationStatus::DELETED->value,
        ], true)) {
            return response()->json([
                'error' => [
                    'code' => 'OPERATE_AS_ORG_INACTIVE',
                    'message' => 'The target organization is not active.',
                ],
            ], 403);
        }

        $this->auditService->log(
            request: $request,
            action: 'operate_as.started',
            targetOrganizationId: $organization->id,
            targetEntityType: 'Organization',
            targetEntityId: $organization->id,
            reason: $request->input('reason'),
        );

        return response()->json([
            'data' => [
                'organization' => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'status' => $organization->status,
                    'timezone' => $organization->timezone,
                ],
            ],
        ]);
    }

    /**
     * Stop operating as an organization.
     *
     * Records an `operate_as.stopped` audit log entry. The target organization
     * id may optionally be supplied via `organization_id` (query or body) so the
     * audit trail can attribute the exit to a specific organization.
     */
    public function stop(Request $request): Response
    {
        $targetOrganizationId = $request->input('organization_id');

        $this->auditService->log(
            request: $request,
            action: 'operate_as.stopped',
            targetOrganizationId: $targetOrganizationId !== null ? (int) $targetOrganizationId : null,
        );

        return response()->noContent();
    }
}
