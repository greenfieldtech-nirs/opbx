<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Enums\OrganizationStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Scopes\OrganizationScope;
use Illuminate\Http\JsonResponse;

/**
 * Platform Dashboard Controller
 *
 * Provides platform-wide statistics for the platform manager dashboard.
 */
class PlatformDashboardController extends Controller
{
    /**
     * Get platform-wide dashboard statistics.
     */
    public function index(): JsonResponse
    {
        // Use bypass to get counts across all organizations
        $stats = OrganizationScope::bypass(function () {
            return [
                'organizations' => $this->getOrganizationStats(),
                'users' => $this->getUserStats(),
                'extensions' => $this->getExtensionStats(),
                'dids' => $this->getDidStats(),
                'recent_organizations' => $this->getRecentOrganizations(),
                'recent_audit_logs' => $this->getRecentAuditLogs(),
            ];
        });

        return response()->json(['data' => $stats]);
    }

    /**
     * Get organization statistics by status.
     */
    private function getOrganizationStats(): array
    {
        return [
            'total' => Organization::count(),
            'active' => Organization::where('status', OrganizationStatus::ACTIVE->value)->count(),
            'suspended' => Organization::where('status', OrganizationStatus::SUSPENDED->value)->count(),
            'deleted' => Organization::where('status', OrganizationStatus::DELETED->value)->count(),
        ];
    }

    /**
     * Get user statistics by status.
     */
    private function getUserStats(): array
    {
        return [
            'total' => User::count(),
            'active' => User::where('status', UserStatus::ACTIVE)->count(),
            'inactive' => User::where('status', UserStatus::INACTIVE)->count(),
            'platform_managers' => User::where('is_platform_manager', true)->count(),
        ];
    }

    /**
     * Get extension statistics.
     */
    private function getExtensionStats(): array
    {
        return [
            'total' => Extension::count(),
        ];
    }

    /**
     * Get DID number statistics.
     */
    private function getDidStats(): array
    {
        return [
            'total' => DidNumber::count(),
        ];
    }

    /**
     * Get 10 most recent organizations.
     */
    private function getRecentOrganizations(): array
    {
        return Organization::query()
            ->select([
                'id',
                'name',
                'slug',
                'status',
                'timezone',
                'created_at',
                'updated_at',
            ])
            ->withCount(['users', 'extensions', 'didNumbers as dids_count'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($org) => [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'status' => $org->status,
                'timezone' => $org->timezone,
                'users_count' => $org->users_count,
                'extensions_count' => $org->extensions_count,
                'dids_count' => $org->dids_count,
                'created_at' => $org->created_at?->toIso8601String(),
                'updated_at' => $org->updated_at?->toIso8601String(),
            ])
            ->toArray();
    }

    /**
     * Get 10 most recent audit log entries.
     */
    private function getRecentAuditLogs(): array
    {
        return PlatformAuditLog::query()
            ->with([
                'platformManager:id,name,email',
                'targetOrganization:id,name,slug',
            ])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'platform_manager_user_id' => $log->platform_manager_user_id,
                'platform_manager' => $log->platformManager ? [
                    'id' => $log->platformManager->id,
                    'name' => $log->platformManager->name,
                    'email' => $log->platformManager->email,
                ] : null,
                'target_organization_id' => $log->target_organization_id,
                'target_organization' => $log->targetOrganization ? [
                    'id' => $log->targetOrganization->id,
                    'name' => $log->targetOrganization->name,
                    'slug' => $log->targetOrganization->slug,
                ] : null,
                'action' => $log->action,
                'target_entity_type' => $log->target_entity_type,
                'target_entity_id' => $log->target_entity_id,
                'before_state' => $log->before_state,
                'after_state' => $log->after_state,
                'reason' => $log->reason,
                'created_at' => $log->created_at?->toIso8601String(),
            ])
            ->toArray();
    }
}
