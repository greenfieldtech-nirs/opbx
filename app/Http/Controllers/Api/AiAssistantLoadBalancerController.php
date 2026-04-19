<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AlbsStatus;
use App\Enums\AlbsStrategy;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Resources\AiAssistantLoadBalancerResource;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AI Assistant Load Balancer management API controller.
 *
 * Handles CRUD operations for AI Assistant Load Balancers within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class AiAssistantLoadBalancerController extends AbstractApiCrudController
{
    use AppliesFilters;

    private const LOCK_TIMEOUT_SECONDS = 10;

    private const DEFAULT_LOCK_BLOCK_SECONDS = 5;

    protected function getModelClass(): string
    {
        return AiAssistantLoadBalancer::class;
    }

    protected function getResourceClass(): string
    {
        return AiAssistantLoadBalancerResource::class;
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
        return 'ai_assistant_load_balancer';
    }

    protected function buildIndexQuery(Builder $query, Request $request): void
    {
        $query->with([
            'members' => function ($query) {
                $query->select('id', 'load_balancer_id', 'ai_assistant_id', 'priority', 'weight', 'position', 'status')
                    ->orderBy('position', 'asc');
            },
            'members.aiAssistant' => function ($query) {
                $query->select('id', 'name', 'status');
            },
            'fallbackExtension:id,extension_number',
            'fallbackRingGroup:id,name',
            'fallbackIvrMenu:id,name',
            'fallbackAiAssistant:id,name',
        ])
            ->withCount([
                'members',
                'members as active_members_count' => function ($query) {
                    $query->whereHas('aiAssistant', function ($q) {
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
                'enum' => AlbsStrategy::class,
                'scope' => 'withStrategy',
            ],
            'status' => [
                'type' => 'enum',
                'enum' => AlbsStatus::class,
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
     * @param  AiAssistantLoadBalancer|null  $loadBalancer  Existing load balancer (null for store)
     * @return array Normalized validated data with correct fallback IDs
     */
    protected function normalizeFallbackFields(array $validated, ?AiAssistantLoadBalancer $loadBalancer = null): array
    {
        // Determine the active fallback action
        $action = $validated['fallback_action'] ?? ($loadBalancer?->fallback_action->value ?? null);

        // Preserve incoming fallback IDs before clearing
        $incomingExtensionId = $validated['fallback_extension_id'] ?? null;
        $incomingRingGroupId = $validated['fallback_ring_group_id'] ?? null;
        $incomingIvrMenuId = $validated['fallback_ivr_menu_id'] ?? null;
        $incomingAiAssistantId = $validated['fallback_ai_assistant_id'] ?? null;

        // Clear all fallback IDs first
        $validated['fallback_extension_id'] = null;
        $validated['fallback_ring_group_id'] = null;
        $validated['fallback_ivr_menu_id'] = null;
        $validated['fallback_ai_assistant_id'] = null;

        // Set only the relevant fallback ID based on action type
        switch ($action) {
            case 'extension':
                $validated['fallback_extension_id'] = $incomingExtensionId
                    ?? $loadBalancer?->fallback_extension_id;
                break;
            case 'ring_group':
                $validated['fallback_ring_group_id'] = $incomingRingGroupId
                    ?? $loadBalancer?->fallback_ring_group_id;
                break;
            case 'ivr_menu':
                $validated['fallback_ivr_menu_id'] = $incomingIvrMenuId
                    ?? $loadBalancer?->fallback_ivr_menu_id;
                break;
            case 'ai_assistant':
                $validated['fallback_ai_assistant_id'] = $incomingAiAssistantId
                    ?? $loadBalancer?->fallback_ai_assistant_id;
                break;
                // Other actions (hangup) don't need fallback IDs
        }

        return $validated;
    }

    /**
     * Temporary storage for members data during store operation.
     */
    private array $tempMembersData = [];

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
        // Create load balancer members
        foreach ($this->tempMembersData as $memberData) {
            AiAssistantLoadBalancerMember::create([
                'load_balancer_id' => $model->id,
                'ai_assistant_id' => $memberData['ai_assistant_id'],
                'priority' => $memberData['priority'] ?? 0,
                'weight' => $memberData['weight'] ?? 100,
                'position' => $memberData['position'] ?? 0,
                'status' => $memberData['status'] ?? 'active',
            ]);
        }

        // Clear cache for this load balancer
        Cache::forget("albs:{$model->id}");
        Cache::forget("albs:rr:{$model->id}");

        // Load relationships
        $model->loadMissing(AiAssistantLoadBalancer::DEFAULT_RELATIONSHIP_FIELDS);
    }

    protected function afterShow(Model $model, Request $request): void
    {
        // Load relationships
        $model->loadMissing(AiAssistantLoadBalancer::DEFAULT_RELATIONSHIP_FIELDS);
    }

    protected function acquireUpdateLock(Model $model, Request $request): ?\Illuminate\Contracts\Cache\Lock
    {
        $lockKey = "lock:albs:{$model->id}";
        $lock = Cache::lock($lockKey, self::LOCK_TIMEOUT_SECONDS);

        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        // Try to acquire lock with timeout
        if (! $lock->block(self::DEFAULT_LOCK_BLOCK_SECONDS)) {
            Log::warning('Failed to acquire load balancer lock', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'albs_id' => $model->id,
                'lock_key' => $lockKey,
            ]);

            return response()->json([
                'error' => 'Conflict',
                'message' => 'Load balancer is currently being modified. Please try again.',
            ], 409);
        }

        Log::debug('Load balancer lock acquired', [
            'request_id' => $requestId,
            'albs_id' => $model->id,
            'lock_key' => $lockKey,
        ]);

        return $lock;
    }

    protected function releaseUpdateLock(?\Illuminate\Contracts\Cache\Lock $lock, Model $model, Request $request): void
    {
        if ($lock) {
            $lock->release();

            $requestId = $this->getRequestId();
            Log::debug('Load balancer lock released', [
                'request_id' => $requestId,
                'albs_id' => $model->id,
                'lock_key' => "lock:albs:{$model->id}",
            ]);
        }
    }

    protected function beforeUpdate(Model $model, array $validated, Request $request): array
    {
        // Check if members data is being updated
        $hasMembersUpdate = isset($validated['members']) && is_array($validated['members']);

        // Only extract members if they are being updated
        if ($hasMembersUpdate) {
            $this->tempMembersData = $validated['members'];
            unset($validated['members']);
        } else {
            // Preserve existing members by not including them in the update
            $this->tempMembersData = [];
        }

        // Normalize fallback fields based on action
        $validated = $this->normalizeFallbackFields($validated, $model);

        return $validated;
    }

    protected function afterUpdate(Model $model, Request $request): void
    {
        // Only update members if they were included in the request
        if (! empty($this->tempMembersData)) {
            // Delete existing members
            AiAssistantLoadBalancerMember::where('load_balancer_id', $model->id)->delete();

            // Create new members
            foreach ($this->tempMembersData as $memberData) {
                AiAssistantLoadBalancerMember::create([
                    'load_balancer_id' => $model->id,
                    'ai_assistant_id' => $memberData['ai_assistant_id'],
                    'priority' => $memberData['priority'] ?? 0,
                    'weight' => $memberData['weight'] ?? 100,
                    'position' => $memberData['position'] ?? 0,
                    'status' => $memberData['status'] ?? 'active',
                ]);
            }
        }

        // Clear cache for this load balancer
        Cache::forget("albs:{$model->id}");
        Cache::forget("albs:rr:{$model->id}");

        // Reload relationships
        $model->loadMissing(AiAssistantLoadBalancer::DEFAULT_RELATIONSHIP_FIELDS);
    }

    /**
     * Check for references before deleting a load balancer.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
        $this->checkResourceReferencesBeforeDelete(
            'ai_assistant_load_balancer',
            $model->id,
            $model->organization_id
        );
    }

    /**
     * Clear cache after deleting a load balancer.
     */
    protected function afterDestroy(Model $model, Request $request): void
    {
        Cache::forget("albs:{$model->id}");
        Cache::forget("albs:rr:{$model->id}");
    }
}
