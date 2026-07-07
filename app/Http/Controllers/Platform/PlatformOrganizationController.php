<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Enums\OrganizationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdateOrganizationSettingsRequest;
use App\Http\Requests\Platform\UpdateOrganizationStatusRequest;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform Organization Controller
 *
 * Manages cross-tenant organization operations for platform managers.
 */
class PlatformOrganizationController extends Controller
{
    /**
     * List all organizations with counts.
     */
    public function index(Request $request): JsonResponse
    {
        // Bypass organization scope to get counts across all organizations
        $organizations = OrganizationScope::bypass(function () use ($request) {
            $query = Organization::query()
                ->withCount(['users', 'extensions', 'didNumbers as dids_count']);

            // Search by name or slug
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            }

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            // Sort
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $allowedSorts = ['id', 'name', 'status', 'created_at', 'users_count', 'extensions_count', 'dids_count'];

            if (in_array($sortBy, $allowedSorts, true)) {
                $query->orderBy($sortBy, $sortDirection === 'asc' ? 'asc' : 'desc');
            }

            $perPage = $request->input('per_page', 25);

            $paginator = $query->paginate(min($perPage, 100));

            return [
                'data' => $paginator->items(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ];
        });

        return response()->json($organizations);
    }

    /**
     * Show a single organization with details.
     */
    public function show(Organization $organization): JsonResponse
    {
        // Bypass organization scope to load all users across all organizations
        $organizationData = OrganizationScope::bypass(function () use ($organization) {
            $organization->load([
                'users:id,organization_id,name,email,role,status,created_at',
            ]);

            $organization->loadCount([
                'users',
                'extensions',
                'didNumbers as dids_count',
                'ringGroups',
                'businessHoursSchedules as business_hours_count',
            ]);

            // Mask sensitive Cloudonix settings
            $settings = $organization->settings;
            if (is_array($settings) && isset($settings['cloudonix'])) {
                $settings['cloudonix'] = $this->maskSensitiveSettings($settings['cloudonix']);
            }

            return array_merge(
                $organization->toArray(),
                ['settings' => $settings]
            );
        });

        return response()->json([
            'data' => $organizationData,
        ]);
    }

    /**
     * Update organization settings.
     */
    public function update(
        UpdateOrganizationSettingsRequest $request,
        Organization $organization,
        PlatformAuditService $auditService
    ): JsonResponse {
        $validated = $request->validated();
        $beforeState = $organization->only(['name', 'timezone', 'settings']);

        // Update only provided fields
        if (isset($validated['name'])) {
            $organization->name = $validated['name'];
        }
        if (isset($validated['timezone'])) {
            $organization->timezone = $validated['timezone'];
        }
        if (isset($validated['settings'])) {
            $currentSettings = $organization->settings ?? [];
            $organization->settings = array_merge($currentSettings, $validated['settings']);
        }

        $organization->save();

        // Audit log
        $auditService->log(
            request: $request,
            action: 'organization.settings.updated',
            targetOrganizationId: $organization->id,
            targetEntityType: 'Organization',
            targetEntityId: $organization->id,
            beforeState: $beforeState,
            afterState: $organization->only(['name', 'timezone', 'settings']),
        );

        return response()->json([
            'message' => 'Organization updated successfully.',
            'data' => $organization->fresh(),
        ]);
    }

    /**
     * Update organization status.
     */
    public function updateStatus(
        UpdateOrganizationStatusRequest $request,
        Organization $organization,
        PlatformAuditService $auditService
    ): JsonResponse {
        $validated = $request->validated();
        $newStatus = OrganizationStatus::from($validated['status']);
        $currentStatus = $organization->status;

        // Validate status transitions
        $allowedTransitions = $this->getAllowedTransitions($currentStatus);

        if (! in_array($newStatus, $allowedTransitions, true)) {
            return response()->json([
                'message' => "Cannot transition from {$currentStatus} to {$newStatus->value}.",
                'allowed_transitions' => array_map(
                    fn (OrganizationStatus $s) => $s->value,
                    $allowedTransitions
                ),
            ], 422);
        }

        // Check for platform manager users before deletion
        if ($newStatus === OrganizationStatus::DELETED) {
            $platformManagerCount = User::where('organization_id', $organization->id)
                ->where('is_platform_manager', true)
                ->count();

            if ($platformManagerCount > 0) {
                return response()->json([
                    'message' => 'Cannot delete organization: one or more users in this organization are Platform Managers. Please revoke Platform Manager status from all users in this organization before deleting.',
                ], 422);
            }
        }

        $beforeState = ['status' => $currentStatus];

        // For hard delete with cascade
        if ($newStatus === OrganizationStatus::DELETED) {
            // Capture organization data before deletion for audit log
            $organizationData = $organization->toArray();
            $organizationId = $organization->id;

            DB::transaction(function () use ($organization) {
                // Delete in correct order to respect foreign keys
                // 1. Delete users' tokens first
                $userIds = User::where('organization_id', $organization->id)->pluck('id');
                if ($userIds->isNotEmpty()) {
                    DB::table('personal_access_tokens')->whereIn('tokenable_id', $userIds)->delete();
                }

                // 2. Delete extensions (and their related data)
                $organization->extensions()->delete();

                // 3. Delete ring groups
                $organization->ringGroups()->delete();

                // 4. Delete business hours schedules
                $organization->businessHoursSchedules()->delete();

                // 5. Delete DID numbers
                $organization->didNumbers()->delete();

                // 6. Delete call logs
                $organization->callLogs()->delete();

                // 7. Delete users
                $organization->users()->delete();

                // 8. Finally delete the organization (forceDelete for hard delete)
                $organization->forceDelete();
            });

            // Audit log for deletion
            $auditService->log(
                request: $request,
                action: 'organization.deleted',
                targetOrganizationId: null, // Organization no longer exists
                targetEntityType: 'Organization',
                targetEntityId: $organizationId,
                beforeState: $organizationData,
                afterState: null,
                reason: $validated['reason'] ?? null,
            );

            return response()->json([
                'message' => 'Organization deleted successfully.',
                'data' => [
                    'id' => $organizationId,
                    'status' => OrganizationStatus::DELETED->value,
                    'previous_status' => $currentStatus,
                ],
            ]);
        }

        // For other status transitions (active <-> suspended)
        $organization->status = $newStatus->value;
        $organization->save();

        // Audit log
        $auditService->log(
            request: $request,
            action: 'organization.status.updated',
            targetOrganizationId: $organization->id,
            targetEntityType: 'Organization',
            targetEntityId: $organization->id,
            beforeState: $beforeState,
            afterState: ['status' => $newStatus->value],
            reason: $validated['reason'] ?? null,
        );

        return response()->json([
            'message' => 'Organization status updated successfully.',
            'data' => [
                'id' => $organization->id,
                'status' => $newStatus->value,
                'previous_status' => $currentStatus,
            ],
        ]);
    }

    /**
     * Get allowed status transitions.
     *
     * @return array<OrganizationStatus>
     */
    private function getAllowedTransitions(string $currentStatus): array
    {
        $transitions = [
            OrganizationStatus::ACTIVE->value => [
                OrganizationStatus::SUSPENDED,
                OrganizationStatus::DELETED,
            ],
            OrganizationStatus::SUSPENDED->value => [
                OrganizationStatus::ACTIVE,
                OrganizationStatus::DELETED,
            ],
            OrganizationStatus::DELETED->value => [],
        ];

        return $transitions[$currentStatus] ?? [];
    }

    /**
     * Mask sensitive settings fields.
     */
    private function maskSensitiveSettings(array $settings): array
    {
        $sensitiveKeys = [
            'domain_api_key',
            'domain_requests_api_key',
            'domain_cdr_auth_key',
            'api_key',
            'api_secret',
            'password',
            'secret',
            'token',
        ];

        foreach ($settings as $key => $value) {
            if (is_string($value) && in_array($key, $sensitiveKeys, true) && strlen($value) > 4) {
                $settings[$key] = str_repeat('*', strlen($value) - 4).substr($value, -4);
            }
        }

        return $settings;
    }
}
