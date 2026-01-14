<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\RingGroupStatus;
use App\Enums\RingGroupStrategy;
use App\Http\Requests\RingGroup\StoreRingGroupRequest;
use App\Http\Requests\RingGroup\UpdateRingGroupRequest;
use App\Http\Resources\RingGroupResource;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ring Groups management API controller.
 *
 * Handles CRUD operations for ring groups within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class RingGroupController extends AbstractApiCrudController
{
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

    protected function buildIndexQuery(Builder $query, Request $request): void
    {
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

    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        if ($request->has('strategy')) {
            $strategy = RingGroupStrategy::tryFrom($request->input('strategy'));
            if ($strategy) {
                $query->withStrategy($strategy);
            }
        }

        if ($request->has('status')) {
            $status = RingGroupStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->withStatus($status);
            }
        }

        if ($request->has('search') && $request->filled('search')) {
            $query->search($request->input('search'));
        }
    }

    protected function beforeStore(array $validated, Request $request): array
    {
        // Extract members data for later processing
        $this->tempMembersData = $validated['members'] ?? [];
        unset($validated['members']);

        // Ensure only the relevant fallback ID is set based on the action
        $action = $validated['fallback_action'] ?? null;
        $validated['fallback_extension_id'] = ($action === 'extension') ? ($validated['fallback_extension_id'] ?? null) : null;
        $validated['fallback_ring_group_id'] = ($action === 'ring_group') ? ($validated['fallback_ring_group_id'] ?? null) : null;
        $validated['fallback_ivr_menu_id'] = ($action === 'ivr_menu') ? ($validated['fallback_ivr_menu_id'] ?? null) : null;
        $validated['fallback_ai_assistant_id'] = ($action === 'ai_assistant') ? ($validated['fallback_ai_assistant_id'] ?? null) : null;

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
        $model->load(['members.extension.user:id,name', 'fallbackExtension:id,extension_number']);
    }

    private array $tempMembersData = [];

    protected function afterShow(Model $model, Request $request): void
    {
        // Load relationships
        $model->load(['members.extension.user:id,name', 'fallbackExtension:id,extension_number']);
    }

    protected function acquireUpdateLock(Model $model, Request $request): ?\Illuminate\Contracts\Cache\Lock
    {
        $lockKey = "lock:ring_group:{$model->id}";
        $lock = Cache::lock($lockKey, 30);
        
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        // Try to acquire lock with 5 second timeout
        if (!$lock->block(5)) {
            Log::warning('Failed to acquire ring group lock', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'ring_group_id' => $model->id,
                'lock_key' => $lockKey,
            ]);

            abort(409, 'Ring group is currently being modified. Please try again.');
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

        // Ensure only the relevant fallback ID is set based on the action
        $action = $validated['fallback_action'] ?? $model->fallback_action->value;

        $validated['fallback_extension_id'] = ($action === 'extension') ? ($validated['fallback_extension_id'] ?? $model->fallback_extension_id) : null;
        $validated['fallback_ring_group_id'] = ($action === 'ring_group') ? ($validated['fallback_ring_group_id'] ?? $model->fallback_ring_group_id) : null;
        $validated['fallback_ivr_menu_id'] = ($action === 'ivr_menu') ? ($validated['fallback_ivr_menu_id'] ?? $model->fallback_ivr_menu_id) : null;
        $validated['fallback_ai_assistant_id'] = ($action === 'ai_assistant') ? ($validated['fallback_ai_assistant_id'] ?? $model->fallback_ai_assistant_id) : null;

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
        $model->load(['members.extension.user:id,name', 'fallbackExtension:id,extension_number']);
    }

}
