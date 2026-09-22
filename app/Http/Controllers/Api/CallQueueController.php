<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CallQueueStatus;
use App\Enums\CallQueueStrategy;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Requests\CallQueue\StoreCallQueueRequest;
use App\Http\Requests\CallQueue\UpdateCallQueueRequest;
use App\Http\Resources\CallQueueResource;
use App\Models\CallQueue;
use App\Models\CallQueueAgent;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Call Queues management API controller.
 *
 * Handles CRUD operations for call queues within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class CallQueueController extends AbstractApiCrudController
{
    use AppliesFilters;

    private const LOCK_TIMEOUT_SECONDS = 10;

    private const DEFAULT_LOCK_BLOCK_SECONDS = 5;

    protected function getModelClass(): string
    {
        return CallQueue::class;
    }

    protected function getResourceClass(): string
    {
        return CallQueueResource::class;
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
        return 'call_queue';
    }

    protected function getStoreRequestClass(): ?string
    {
        return StoreCallQueueRequest::class;
    }

    protected function getUpdateRequestClass(): ?string
    {
        return UpdateCallQueueRequest::class;
    }

    protected function buildIndexQuery(Builder $query, Request $request): void
    {
        $query->with([
            'agents.extension:id,user_id,extension_number,status',
            'mohRecording:id,name',
            'fallbackExtension:id,extension_number',
            'fallbackRingGroup:id,name',
            'fallbackIvrMenu:id,name',
            'fallbackAiAssistant:id,name',
            'fallbackAiLoadBalancer:id,name',
        ])->withCount('agents');
    }

    /**
     * Toggle the queue between active and inactive.
     */
    public function toggleStatus(Request $request): \Illuminate\Http\JsonResponse
    {
        $model = $this->resolveModel($request);
        $this->authorize($this->getUpdateAbility(), $model);

        $model->status = $model->status === CallQueueStatus::ACTIVE
            ? CallQueueStatus::INACTIVE
            : CallQueueStatus::ACTIVE;
        $model->save();

        $this->logOperationCompleted($this->getResourceKey(), 'status_toggle', [
            'user_id' => $this->getAuthenticatedUser()->id,
            'call_queue_id' => $model->id,
            'status' => $model->status->value,
        ]);

        return response()->json([
            'message' => 'Call queue '.($model->status === CallQueueStatus::ACTIVE ? 'activated' : 'deactivated').'.',
            'data' => new ($this->getResourceClass())($model),
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
                'enum' => CallQueueStrategy::class,
                'scope' => 'withStrategy',
            ],
            'status' => [
                'type' => 'enum',
                'enum' => CallQueueStatus::class,
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
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function normalizeFallbackFields(array $validated, ?CallQueue $callQueue = null): array
    {
        $action = $validated['fallback_action'] ?? ($callQueue?->fallback_action->value ?? null);

        $incoming = [
            'fallback_extension_id' => $validated['fallback_extension_id'] ?? null,
            'fallback_ring_group_id' => $validated['fallback_ring_group_id'] ?? null,
            'fallback_ivr_menu_id' => $validated['fallback_ivr_menu_id'] ?? null,
            'fallback_ai_assistant_id' => $validated['fallback_ai_assistant_id'] ?? null,
            'fallback_ai_load_balancer_id' => $validated['fallback_ai_load_balancer_id'] ?? null,
        ];

        $validated['fallback_extension_id'] = null;
        $validated['fallback_ring_group_id'] = null;
        $validated['fallback_ivr_menu_id'] = null;
        $validated['fallback_ai_assistant_id'] = null;
        $validated['fallback_ai_load_balancer_id'] = null;

        $field = match ($action) {
            'extension' => 'fallback_extension_id',
            'ring_group' => 'fallback_ring_group_id',
            'ivr_menu' => 'fallback_ivr_menu_id',
            'ai_assistant' => 'fallback_ai_assistant_id',
            'ai_load_balancer' => 'fallback_ai_load_balancer_id',
            default => null,
        };

        if ($field !== null) {
            $validated[$field] = $incoming[$field] ?? $callQueue?->{$field};
        }

        return $validated;
    }

    /**
     * @var array<int>
     */
    private array $tempAgentIds = [];

    protected function beforeStore(array $validated, Request $request): array
    {
        $this->tempAgentIds = $validated['agents'] ?? [];
        unset($validated['agents']);

        $validated = $this->normalizeAnnounceFields($validated);

        return $this->normalizeFallbackFields($validated);
    }

    protected function afterStore(Model $model, Request $request): void
    {
        $this->syncAgents($model);

        $model->loadMissing(['agents.extension:id,user_id,extension_number,status', 'mohRecording:id,name']);
    }

    protected function afterShow(Model $model, Request $request): void
    {
        $model->loadMissing(['agents.extension:id,user_id,extension_number,status', 'mohRecording:id,name']);
    }

    protected function beforeUpdate(Model $model, array $validated, Request $request): array
    {
        $this->tempAgentIds = $validated['agents'] ?? [];
        unset($validated['agents']);

        $validated = $this->normalizeAnnounceFields($validated);

        return $this->normalizeFallbackFields($validated, $model);
    }

    /**
     * The announce interval column is NOT NULL; default it when the client
     * sends null (announce disabled) instead of rejecting the payload.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeAnnounceFields(array $validated): array
    {
        if (array_key_exists('announce_position_timeout', $validated) && $validated['announce_position_timeout'] === null) {
            $validated['announce_position_timeout'] = 60;
        }

        return $validated;
    }

    protected function afterUpdate(Model $model, Request $request): void
    {
        $this->syncAgents($model);

        $model->loadMissing(['agents.extension:id,user_id,extension_number,status', 'mohRecording:id,name']);
    }

    /**
     * Delete-and-recreate the queue's agent list from the request payload.
     */
    private function syncAgents(CallQueue $callQueue): void
    {
        CallQueueAgent::where('call_queue_id', $callQueue->id)->delete();

        foreach ($this->tempAgentIds as $userId) {
            CallQueueAgent::create([
                'organization_id' => $callQueue->organization_id,
                'call_queue_id' => $callQueue->id,
                'user_id' => $userId,
            ]);
        }
    }

    protected function acquireUpdateLock(Model $model, Request $request): ?Lock
    {
        $lockKey = "lock:call_queue:{$model->id}";
        $lock = Cache::lock($lockKey, self::LOCK_TIMEOUT_SECONDS);

        if (! $lock->block(self::DEFAULT_LOCK_BLOCK_SECONDS)) {
            // ponytail: proceed without the lock rather than failing the request;
            // queue config updates are rare and the lock is only a guard against
            // concurrent edit races, not a correctness requirement.
            Log::warning('Failed to acquire call queue lock, proceeding without lock', [
                'request_id' => $this->getRequestId(),
                'call_queue_id' => $model->id,
                'lock_key' => $lockKey,
            ]);

            return null;
        }

        return $lock;
    }

    protected function releaseUpdateLock(?Lock $lock, Model $model, Request $request): void
    {
        if ($lock) {
            $lock->release();
        }
    }

    /**
     * Check for references before deleting a call queue.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
        $this->checkResourceReferencesBeforeDelete(
            'call_queue',
            $model->id,
            $model->organization_id
        );
    }
}
