<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\RingGroupStatus;
use App\Enums\RingGroupStrategy;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Resources\RingGroupResource;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Ring Groups management API controller.
 *
 * Handles CRUD operations for ring groups within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class RingGroupController extends AbstractApiCrudController
{
    use AppliesFilters;

    private const LOCK_TIMEOUT_SECONDS = 10;

    private const DEFAULT_LOCK_BLOCK_SECONDS = 5;

    protected function getModelClass(): string
    {
        return RingGroup::class;
    }

    protected function getResourceClass(): string
    {
        return RingGroupResource::class;
    }

    protected function getAllowedFilters(): array
    {
        return ['strategy', 'status', 'search'];
    }

    protected function getAllowedSortFields(): array
    {
        return ['name', 'strategy', 'status', 'created_at', 'updated_at'];
    }

    protected function getDefaultSortField(): string
    {
        return 'created_at';
    }

    protected function getDefaultSortOrder(): string
    {
        return 'desc';
    }

    protected function getRouteParameterName(): string
    {
        return 'ring_group';
    }

    protected function buildIndexQuery(Builder $query, Request $request): void
    {
        // Supervisors only see ring groups they are assigned to (empty assignment => empty list).
        $currentUser = $request->user();
        if ($currentUser && $currentUser->isSupervisor()) {
            $assignedRingGroupIds = $currentUser->supervisedRingGroups
                ->pluck('id')
                ->all();

            $query->whereIn('id', $assignedRingGroupIds);
        }

        $query->with([
            'members' => function ($query) {
                $query->select('id', 'ring_group_id', 'extension_id', 'priority')
                    ->orderBy('priority', 'asc');
            },
            'members.extension' => function ($query) {
                $query->select('id', 'user_id', 'extension_number', 'status');
            },
            'members.extension.user:id,name',
            'fallbackExtension:id,extension_number',
        ])
            ->withCount([
                'members',
                'members as active_members_count' => function ($query) {
                    $query->whereHas('extension', function ($q) {
                        $q->where('status', 'active');
                    });
                },
            ]);
    }

    /**
     * Get the filter configuration for the index method.
     *
     * @return array<string, array>
     */
    protected function getFilterConfig(): array
    {
        return [
            'strategy' => [
                'type' => 'enum',
                'enum' => RingGroupStrategy::class,
                'scope' => 'withStrategy',
            ],
            'status' => [
                'type' => 'enum',
                'enum' => RingGroupStatus::class,
                'scope' => 'withStatus',
            ],
            'search' => [
                'type' => 'search',
                'scope' => 'search',
            ],
        ];
    }

    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        $this->applyFilters($query, $request, $this->getFilterConfig());
    }

    /**
     * Normalize fallback fields based on fallback action.
     * Ensures only the relevant fallback ID is set based on the action type.
     *
     * @param  array  $validated  Validated request data
     * @param  RingGroup|null  $ringGroup  Existing ring group for fallback values (null for store)
     * @return array Normalized validated data with correct fallback IDs
     */
    protected function normalizeFallbackFields(array $validated, ?RingGroup $ringGroup = null): array
    {
        // Determine the active fallback action
        $action = $validated['fallback_action'] ?? ($ringGroup?->fallback_action->value ?? null);

        // Preserve incoming fallback IDs before clearing
        $incomingExtensionId = $validated['fallback_extension_id'] ?? null;
        $incomingRingGroupId = $validated['fallback_ring_group_id'] ?? null;
        $incomingIvrMenuId = $validated['fallback_ivr_menu_id'] ?? null;
        $incomingAiAssistantId = $validated['fallback_ai_assistant_id'] ?? null;
        $incomingAiLoadBalancerId = $validated['fallback_ai_load_balancer_id'] ?? null;

        // Clear all fallback IDs first
        $validated['fallback_extension_id'] = null;
        $validated['fallback_ring_group_id'] = null;
        $validated['fallback_ivr_menu_id'] = null;
        $validated['fallback_ai_assistant_id'] = null;
        $validated['fallback_ai_load_balancer_id'] = null;

        // Set only the relevant fallback ID based on action type
        switch ($action) {
            case 'extension':
                $validated['fallback_extension_id'] = $incomingExtensionId
                    ?? $ringGroup?->fallback_extension_id;
                break;
            case 'ring_group':
                $validated['fallback_ring_group_id'] = $incomingRingGroupId
                    ?? $ringGroup?->fallback_ring_group_id;
                break;
            case 'ivr_menu':
                $validated['fallback_ivr_menu_id'] = $incomingIvrMenuId
                    ?? $ringGroup?->fallback_ivr_menu_id;
                break;
            case 'ai_assistant':
                $validated['fallback_ai_assistant_id'] = $incomingAiAssistantId
                    ?? $ringGroup?->fallback_ai_assistant_id;
                break;
            case 'ai_load_balancer':
                $validated['fallback_ai_load_balancer_id'] = $incomingAiLoadBalancerId
                    ?? $ringGroup?->fallback_ai_load_balancer_id;
                break;
                // Other actions (voicemail, hangup, etc.) don't need fallback IDs
        }

        return $validated;
    }

    protected function beforeStore(array $validated, Request $request): array
    {
        // Extract members data for later processing
        $this->tempMembersData = $validated['members'] ?? [];
        unset($validated['members']);

        // Normalize fallback fields based on action
        $validated = $this->normalizeFallbackFields($validated);

        return $validated;
    }

    protected function afterStore(Model $model, Request $request): void
    {
        // Create ring group members
        foreach ($this->tempMembersData as $memberData) {
            RingGroupMember::create([
                'ring_group_id' => $model->id,
                'extension_id' => $memberData['extension_id'],
                'priority' => $memberData['priority'],
            ]);
        }

        // Load relationships
        $model->loadMissing(RingGroup::DEFAULT_RELATIONSHIP_FIELDS);
    }

    private array $tempMembersData = [];

    protected function afterShow(Model $model, Request $request): void
    {
        // Load relationships
        $model->loadMissing(RingGroup::DEFAULT_RELATIONSHIP_FIELDS);
    }

    protected function acquireUpdateLock(Model $model, Request $request): ?\Illuminate\Contracts\Cache\Lock
    {
        $lockKey = "lock:ring_group:{$model->id}";
        $lock = Cache::lock($lockKey, self::LOCK_TIMEOUT_SECONDS);

        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        // Try to acquire lock with timeout
        if (! $lock->block(self::DEFAULT_LOCK_BLOCK_SECONDS)) {
            Log::warning('Failed to acquire ring group lock', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'ring_group_id' => $model->id,
                'lock_key' => $lockKey,
            ]);

            return response()->json([
                'error' => 'Conflict',
                'message' => 'Ring group is currently being modified. Please try again.',
            ], 409);
        }

        Log::debug('Ring group lock acquired', [
            'request_id' => $requestId,
            'ring_group_id' => $model->id,
            'lock_key' => $lockKey,
        ]);

        return $lock;
    }

    protected function releaseUpdateLock(?\Illuminate\Contracts\Cache\Lock $lock, Model $model, Request $request): void
    {
        if ($lock) {
            $lock->release();

            $requestId = $this->getRequestId();
            Log::debug('Ring group lock released', [
                'request_id' => $requestId,
                'ring_group_id' => $model->id,
                'lock_key' => "lock:ring_group:{$model->id}",
            ]);
        }
    }

    protected function beforeUpdate(Model $model, array $validated, Request $request): array
    {
        // Extract members data
        $this->tempMembersData = $validated['members'] ?? [];
        unset($validated['members']);

        // Normalize fallback fields based on action
        $validated = $this->normalizeFallbackFields($validated, $model);

        return $validated;
    }

    protected function afterUpdate(Model $model, Request $request): void
    {
        // Delete existing members
        RingGroupMember::where('ring_group_id', $model->id)->delete();

        // Create new members
        foreach ($this->tempMembersData as $memberData) {
            RingGroupMember::create([
                'ring_group_id' => $model->id,
                'extension_id' => $memberData['extension_id'],
                'priority' => $memberData['priority'],
            ]);
        }

        // Reload relationships
        $model->loadMissing(RingGroup::DEFAULT_RELATIONSHIP_FIELDS);
    }

    /**
     * Check for references before deleting a ring group.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
        $this->checkResourceReferencesBeforeDelete(
            'ring_group',
            $model->id,
            $model->organization_id
        );
    }
}
