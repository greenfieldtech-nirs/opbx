<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConferenceRoom\StoreConferenceRoomRequest;
use App\Http\Requests\ConferenceRoom\UpdateConferenceRoomRequest;
use App\Http\Resources\ConferenceRoomResource;
use App\Models\ConferenceRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Conference Room management API controller.
 *
 * Handles CRUD operations for conference rooms within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class ConferenceRoomController extends Controller
    use ApiRequestHandler;{
    /**
     * Display a paginated list of conference rooms.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        Gate::authorize('viewAny', ConferenceRoom::class);

        // Build query
        $query = ConferenceRoom::query()
            ->forOrganization($user->organization_id);

        // Apply filters
        if ($request->has('status')) {
            $status = UserStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->withStatus($status);
            }
        }

        if ($request->has('search') && $request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Apply sorting
        $sortField = $request->input('sort_by', 'name');
        $sortOrder = $request->input('sort_order', 'asc');

        // Validate sort field
        $allowedSortFields = ['name', 'max_participants', 'status', 'created_at', 'updated_at'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'name';
        }

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : 'asc';

        $query->orderBy($sortField, $sortOrder);

        // Paginate
        $perPage = (int) $request->input('per_page', 20);
        $perPage = min(max($perPage, 1), 100); // Clamp between 1 and 100

        $conferenceRooms = $query->paginate($perPage);

        Log::info('Conference rooms list retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $conferenceRooms->total(),
            'per_page' => $perPage,
            'filters' => [
                'status' => $request->input('status'),
                'search' => $request->input('search'),
            ],
        ]);

        return ConferenceRoomResource::collection($conferenceRooms);
    }

    /**
     * Store a newly created conference room.
     */
    public function store(StoreConferenceRoomRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validated();

        Log::info('Creating new conference room', [
            'request_id' => $requestId,
            'creator_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'name' => $validated['name'],
        ]);

        try {
            $conferenceRoom = DB::transaction(function () use ($currentUser, $validated): ConferenceRoom {
                // Assign to current user's organization
                $validated['organization_id'] = $currentUser->organization_id;

                // Create conference room
                $conferenceRoom = ConferenceRoom::create($validated);

                return $conferenceRoom;
            });

            Log::info('Conference room created successfully', [
                'request_id' => $requestId,
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'conference_room_id' => $conferenceRoom->id,
                'name' => $conferenceRoom->name,
            ]);

            return response()->json([
                'message' => 'Conference room created successfully.',
                'conference_room' => new ConferenceRoomResource($conferenceRoom),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create conference room', [
                'request_id' => $requestId,
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to create conference room',
                'message' => 'An error occurred while creating the conference room.',
            ], 500);
        }
    }

    /**
     * Display the specified conference room.
     */
    public function show(Request $request, ConferenceRoom $conferenceRoom): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Gate::authorize('view', $conferenceRoom);

        // Tenant scope check
        if ($conferenceRoom->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant conference room access attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_conference_room_id' => $conferenceRoom->id,
                'target_organization_id' => $conferenceRoom->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Conference room not found.',
            ], 404);
        }

        Log::info('Conference room details retrieved', [
            'request_id' => $requestId,
            'user_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'conference_room_id' => $conferenceRoom->id,
        ]);

        return response()->json([
            'conference_room' => new ConferenceRoomResource($conferenceRoom),
        ]);
    }

    /**
     * Update the specified conference room.
     */
    public function update(UpdateConferenceRoomRequest $request, ConferenceRoom $conferenceRoom): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Tenant scope check
        if ($conferenceRoom->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant conference room update attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_conference_room_id' => $conferenceRoom->id,
                'target_organization_id' => $conferenceRoom->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Conference room not found.',
            ], 404);
        }

        $validated = $request->validated();

        // Track changed fields for logging
        $changedFields = [];
        foreach ($validated as $key => $value) {
            if ($conferenceRoom->{$key} != $value) {
                $changedFields[] = $key;
            }
        }

        Log::info('Updating conference room', [
            'request_id' => $requestId,
            'updater_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'conference_room_id' => $conferenceRoom->id,
            'changed_fields' => $changedFields,
        ]);

        try {
            DB::transaction(function () use ($conferenceRoom, $validated): void {
                // Update conference room
                $conferenceRoom->update($validated);
            });

            // Reload conference room
            $conferenceRoom->refresh();

            Log::info('Conference room updated successfully', [
                'request_id' => $requestId,
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'conference_room_id' => $conferenceRoom->id,
                'changed_fields' => $changedFields,
            ]);

            return response()->json([
                'message' => 'Conference room updated successfully.',
                'conference_room' => new ConferenceRoomResource($conferenceRoom),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update conference room', [
                'request_id' => $requestId,
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'conference_room_id' => $conferenceRoom->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to update conference room',
                'message' => 'An error occurred while updating the conference room.',
            ], 500);
        }
    }

    /**
     * Remove the specified conference room.
     */
    public function destroy(Request $request, ConferenceRoom $conferenceRoom): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Gate::authorize('delete', $conferenceRoom);

        // Tenant scope check
        if ($conferenceRoom->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant conference room deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_conference_room_id' => $conferenceRoom->id,
                'target_organization_id' => $conferenceRoom->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Conference room not found.',
            ], 404);
        }

        Log::info('Deleting conference room', [
            'request_id' => $requestId,
            'deleter_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'conference_room_id' => $conferenceRoom->id,
            'name' => $conferenceRoom->name,
        ]);

        try {
            DB::transaction(function () use ($conferenceRoom): void {
                // Hard delete the conference room
                $conferenceRoom->delete();
            });

            Log::info('Conference room deleted successfully', [
                'request_id' => $requestId,
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'conference_room_id' => $conferenceRoom->id,
            ]);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete conference room', [
                'request_id' => $requestId,
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'conference_room_id' => $conferenceRoom->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to delete conference room',
                'message' => 'An error occurred while deleting the conference room.',
            ], 500);
        }
    }
}
