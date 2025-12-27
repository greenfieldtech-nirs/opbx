<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\RingGroupStatus;
use App\Enums\RingGroupStrategy;
use App\Http\Controllers\Controller;
use App\Http\Requests\RingGroup\StoreRingGroupRequest;
use App\Http\Requests\RingGroup\UpdateRingGroupRequest;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ring Groups management API controller.
 *
 * Handles CRUD operations for ring groups within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class RingGroupController extends Controller
{
    /**
     * Display a paginated list of ring groups.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Log::info('Retrieving ring groups list', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        // Build query with eager loading and member counts
        $query = RingGroup::query()
            ->forOrganization($user->organization_id)
            ->with([
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

        // Apply filters
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

        // Apply sorting
        $sortField = $request->input('sort', 'created_at');
        $sortOrder = $request->input('order', 'desc');

        // Validate sort field
        $allowedSortFields = ['name', 'strategy', 'status', 'created_at', 'updated_at'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'created_at';
        }

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : 'desc';

        $query->orderBy($sortField, $sortOrder);

        // Paginate
        $perPage = (int) $request->input('per_page', 25);
        $perPage = min(max($perPage, 1), 100); // Clamp between 1 and 100

        $ringGroups = $query->paginate($perPage);

        Log::info('Ring groups list retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $ringGroups->total(),
            'per_page' => $perPage,
        ]);

        return response()->json([
            'data' => $ringGroups->items(),
            'meta' => [
                'current_page' => $ringGroups->currentPage(),
                'per_page' => $ringGroups->perPage(),
                'total' => $ringGroups->total(),
                'last_page' => $ringGroups->lastPage(),
                'from' => $ringGroups->firstItem(),
                'to' => $ringGroups->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created ring group.
     *
     * @param StoreRingGroupRequest $request
     * @return JsonResponse
     */
    public function store(StoreRingGroupRequest $request): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validated();

        Log::info('Creating new ring group', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'ring_group_name' => $validated['name'],
        ]);

        try {
            $ringGroup = DB::transaction(function () use ($user, $validated): RingGroup {
                // Extract members data
                $membersData = $validated['members'] ?? [];
                unset($validated['members']);

                // Assign to current user's organization
                $validated['organization_id'] = $user->organization_id;

                // Create ring group
                $ringGroup = RingGroup::create($validated);

                // Create ring group members
                foreach ($membersData as $memberData) {
                    RingGroupMember::create([
                        'ring_group_id' => $ringGroup->id,
                        'extension_id' => $memberData['extension_id'],
                        'priority' => $memberData['priority'],
                    ]);
                }

                return $ringGroup;
            });

            // Load relationships
            $ringGroup->load(['members.extension.user:id,name', 'fallbackExtension:id,extension_number']);

            Log::info('Ring group created successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ring_group_id' => $ringGroup->id,
                'ring_group_name' => $ringGroup->name,
                'members_count' => $ringGroup->members->count(),
            ]);

            return response()->json([
                'message' => 'Ring group created successfully.',
                'data' => $ringGroup,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create ring group', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to create ring group',
                'message' => 'An error occurred while creating the ring group.',
            ], 500);
        }
    }

    /**
     * Display the specified ring group.
     *
     * @param Request $request
     * @param RingGroup $ringGroup
     * @return JsonResponse
     */
    public function show(Request $request, RingGroup $ringGroup): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Tenant scope check
        if ($ringGroup->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant ring group access attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ring_group_id' => $ringGroup->id,
                'ring_group_organization_id' => $ringGroup->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Ring group not found.',
            ], 404);
        }

        // Load relationships
        $ringGroup->load(['members.extension.user:id,name', 'fallbackExtension:id,extension_number']);

        Log::info('Ring group details retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'ring_group_id' => $ringGroup->id,
        ]);

        return response()->json([
            'data' => $ringGroup,
        ]);
    }

    /**
     * Update the specified ring group.
     *
     * @param UpdateRingGroupRequest $request
     * @param RingGroup $ringGroup
     * @return JsonResponse
     */
    public function update(UpdateRingGroupRequest $request, RingGroup $ringGroup): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Tenant scope check
        if ($ringGroup->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant ring group update attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ring_group_id' => $ringGroup->id,
                'ring_group_organization_id' => $ringGroup->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Ring group not found.',
            ], 404);
        }

        $validated = $request->validated();

        Log::info('Updating ring group', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'ring_group_id' => $ringGroup->id,
        ]);

        // Acquire distributed lock to prevent race conditions during member updates
        $lockKey = "lock:ring_group:{$ringGroup->id}";
        $lock = Cache::lock($lockKey, 30);

        try {
            // Try to acquire lock with 5 second timeout
            if (! $lock->block(5)) {
                Log::warning('Failed to acquire ring group lock', [
                    'request_id' => $requestId,
                    'user_id' => $user->id,
                    'ring_group_id' => $ringGroup->id,
                    'lock_key' => $lockKey,
                ]);

                return response()->json([
                    'error' => 'Conflict',
                    'message' => 'Ring group is currently being modified. Please try again.',
                ], 409);
            }

            Log::debug('Ring group lock acquired', [
                'request_id' => $requestId,
                'ring_group_id' => $ringGroup->id,
                'lock_key' => $lockKey,
            ]);

            DB::transaction(function () use ($ringGroup, $validated): void {
                // Extract members data
                $membersData = $validated['members'] ?? [];
                unset($validated['members']);

                // Update ring group
                $ringGroup->update($validated);

                // Delete existing members
                RingGroupMember::where('ring_group_id', $ringGroup->id)->delete();

                // Create new members
                foreach ($membersData as $memberData) {
                    RingGroupMember::create([
                        'ring_group_id' => $ringGroup->id,
                        'extension_id' => $memberData['extension_id'],
                        'priority' => $memberData['priority'],
                    ]);
                }
            });

            // Reload ring group with relationships
            $ringGroup->refresh();
            $ringGroup->load(['members.extension.user:id,name', 'fallbackExtension:id,extension_number']);

            Log::info('Ring group updated successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ring_group_id' => $ringGroup->id,
                'members_count' => $ringGroup->members->count(),
            ]);

            return response()->json([
                'message' => 'Ring group updated successfully.',
                'data' => $ringGroup,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update ring group', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ring_group_id' => $ringGroup->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to update ring group',
                'message' => 'An error occurred while updating the ring group.',
            ], 500);
        } finally {
            // Always release the lock
            if (isset($lock)) {
                $lock->release();
                Log::debug('Ring group lock released', [
                    'request_id' => $requestId,
                    'ring_group_id' => $ringGroup->id,
                    'lock_key' => $lockKey,
                ]);
            }
        }
    }

    /**
     * Remove the specified ring group.
     *
     * @param Request $request
     * @param RingGroup $ringGroup
     * @return JsonResponse
     */
    public function destroy(Request $request, RingGroup $ringGroup): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check authorization using policy
        $this->authorize('delete', $ringGroup);

        // Tenant scope check (policy already verifies this, but keeping for logging)
        if ($ringGroup->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant ring group deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ring_group_id' => $ringGroup->id,
                'ring_group_organization_id' => $ringGroup->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Ring group not found.',
            ], 404);
        }

        Log::info('Deleting ring group', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'ring_group_id' => $ringGroup->id,
            'ring_group_name' => $ringGroup->name,
        ]);

        try {
            DB::transaction(function () use ($ringGroup): void {
                // Members will be deleted automatically due to cascadeOnDelete in migration
                $ringGroup->delete();
            });

            Log::info('Ring group deleted successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ring_group_id' => $ringGroup->id,
            ]);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete ring group', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ring_group_id' => $ringGroup->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to delete ring group',
                'message' => 'An error occurred while deleting the ring group.',
            ], 500);
        }
    }
}
