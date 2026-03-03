<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PlatformAuditLog;
use Illuminate\Http\Request;

/**
 * Platform Audit Service
 *
 * Service for recording all cross-tenant actions performed by platform managers.
 * Every mutation in the platform management API should be logged via this service.
 */
class PlatformAuditService
{
    /**
     * Log a platform management action.
     *
     * @param  Request  $request  The HTTP request
     * @param  string  $action  The action being performed (e.g., 'organization.status.updated')
     * @param  int|null  $targetOrganizationId  The organization being affected
     * @param  string|null  $targetEntityType  The type of entity being modified (e.g., 'Organization')
     * @param  int|null  $targetEntityId  The ID of the entity being modified
     * @param  array|null  $beforeState  The state before the modification
     * @param  array|null  $afterState  The state after the modification
     * @param  string|null  $reason  Optional reason for the action
     */
    public function log(
        Request $request,
        string $action,
        ?int $targetOrganizationId = null,
        ?string $targetEntityType = null,
        ?int $targetEntityId = null,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?string $reason = null,
    ): PlatformAuditLog {
        return PlatformAuditLog::create([
            'platform_manager_user_id' => $request->user()->id,
            'target_organization_id' => $targetOrganizationId,
            'action' => $action,
            'target_entity_type' => $targetEntityType,
            'target_entity_id' => $targetEntityId,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
