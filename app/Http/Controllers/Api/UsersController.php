<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Users management API controller.
 *
 * Handles CRUD operations for users within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class UsersController extends Controller
{
    /**
     * Display a paginated list of users.
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

        // Check authorization - only Owner and PBX Admin can list users
        if (!$user->isOwner() && !$user->isPBXAdmin()) {
            Log::warning('Unauthorized access to users list', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'role' => $user->role->value,
            ]);

            return response()->json([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to manage users.',
            ], 403);
        }

        // Build query
        $query = User::query()
            ->forOrganization($user->organization_id)
            ->with('extension:id,user_id,extension_number');

        // Apply filters
        if ($request->has('role')) {
            $role = UserRole::tryFrom($request->input('role'));
            if ($role) {
                $query->withRole($role);
            }
        }

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
        $sortField = $request->input('sort', 'created_at');
        $sortOrder = $request->input('order', 'desc');

        // Validate sort field
        $allowedSortFields = ['name', 'email', 'created_at', 'role', 'status'];
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

        $users = $query->paginate($perPage);

        Log::info('Users list retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $users->total(),
            'per_page' => $perPage,
            'filters' => [
                'role' => $request->input('role'),
                'status' => $request->input('status'),
                'search' => $request->input('search'),
            ],
        ]);

        return response()->json([
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created user.
     *
     * @param CreateUserRequest $request
     * @return JsonResponse
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $currentUser = $request->user();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validated();

        Log::info('Creating new user', [
            'request_id' => $requestId,
            'creator_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'new_user_email' => $validated['email'],
            'new_user_role' => $validated['role'],
        ]);

        try {
            $user = DB::transaction(function () use ($currentUser, $validated): User {
                // Hash password
                $validated['password'] = Hash::make($validated['password']);

                // Assign to current user's organization
                $validated['organization_id'] = $currentUser->organization_id;

                // Create user
                $user = User::create($validated);

                return $user;
            });

            // Load extension relationship
            $user->load('extension:id,user_id,extension_number');

            Log::info('User created successfully', [
                'request_id' => $requestId,
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'created_user_id' => $user->id,
                'created_user_email' => $user->email,
            ]);

            return response()->json([
                'message' => 'User created successfully.',
                'user' => $user,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create user', [
                'request_id' => $requestId,
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to create user',
                'message' => 'An error occurred while creating the user.',
            ], 500);
        }
    }

    /**
     * Display the specified user.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $currentUser = $request->user();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check authorization - only Owner and PBX Admin can view user details
        if (!$currentUser->isOwner() && !$currentUser->isPBXAdmin()) {
            Log::warning('Unauthorized access to user details', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_user_id' => $user->id,
                'role' => $currentUser->role->value,
            ]);

            return response()->json([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to view user details.',
            ], 403);
        }

        // Tenant scope check
        if ($user->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant user access attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_user_id' => $user->id,
                'target_organization_id' => $user->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'User not found.',
            ], 404);
        }

        // Load extension relationship
        $user->load('extension:id,user_id,extension_number');

        Log::info('User details retrieved', [
            'request_id' => $requestId,
            'user_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'target_user_id' => $user->id,
        ]);

        return response()->json([
            'user' => $user,
        ]);
    }

    /**
     * Update the specified user.
     *
     * @param UpdateUserRequest $request
     * @param User $user
     * @return JsonResponse
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $currentUser = $request->user();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Tenant scope check
        if ($user->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant user update attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_user_id' => $user->id,
                'target_organization_id' => $user->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'User not found.',
            ], 404);
        }

        $validated = $request->validated();

        // Track changed fields for logging
        $changedFields = [];
        foreach ($validated as $key => $value) {
            if ($key !== 'password' && $user->{$key} != $value) {
                $changedFields[] = $key;
            }
        }

        Log::info('Updating user', [
            'request_id' => $requestId,
            'updater_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'target_user_id' => $user->id,
            'changed_fields' => $changedFields,
        ]);

        try {
            DB::transaction(function () use ($user, $validated): void {
                // Hash password if provided
                if (isset($validated['password']) && !empty($validated['password'])) {
                    $validated['password'] = Hash::make($validated['password']);
                } else {
                    // Remove password from validated data if not provided
                    unset($validated['password']);
                }

                // Update user
                $user->update($validated);
            });

            // Reload user and extension relationship
            $user->refresh();
            $user->load('extension:id,user_id,extension_number');

            Log::info('User updated successfully', [
                'request_id' => $requestId,
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'updated_user_id' => $user->id,
                'changed_fields' => $changedFields,
            ]);

            return response()->json([
                'message' => 'User updated successfully.',
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update user', [
                'request_id' => $requestId,
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_user_id' => $user->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to update user',
                'message' => 'An error occurred while updating the user.',
            ], 500);
        }
    }

    /**
     * Remove the specified user.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $currentUser = $request->user();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check authorization - only Owner and PBX Admin can delete users
        if (!$currentUser->isOwner() && !$currentUser->isPBXAdmin()) {
            Log::warning('Unauthorized user deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_user_id' => $user->id,
                'role' => $currentUser->role->value,
            ]);

            return response()->json([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to delete users.',
            ], 403);
        }

        // Tenant scope check
        if ($user->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant user deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_user_id' => $user->id,
                'target_organization_id' => $user->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'User not found.',
            ], 404);
        }

        // Cannot delete yourself
        if ($currentUser->id === $user->id) {
            Log::warning('Self-deletion attempt blocked', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
            ]);

            return response()->json([
                'error' => 'Conflict',
                'message' => 'You cannot delete yourself.',
            ], 409);
        }

        // Cannot delete if not authorized by role hierarchy
        if (!$currentUser->canManageUser($user)) {
            Log::warning('Insufficient privilege to delete user', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_user_id' => $user->id,
                'current_user_role' => $currentUser->role->value,
                'target_user_role' => $user->role->value,
            ]);

            return response()->json([
                'error' => 'Forbidden',
                'message' => 'You do not have permission to delete this user.',
            ], 403);
        }

        // Cannot delete last owner in organization
        if ($user->role === UserRole::OWNER) {
            $ownerCount = User::forOrganization($currentUser->organization_id)
                ->withRole(UserRole::OWNER)
                ->count();

            if ($ownerCount <= 1) {
                Log::warning('Blocked deletion of last owner', [
                    'request_id' => $requestId,
                    'user_id' => $currentUser->id,
                    'organization_id' => $currentUser->organization_id,
                    'target_user_id' => $user->id,
                ]);

                return response()->json([
                    'error' => 'Conflict',
                    'message' => 'Cannot delete the last owner in the organization.',
                ], 409);
            }
        }

        Log::info('Deleting user', [
            'request_id' => $requestId,
            'deleter_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'target_user_id' => $user->id,
            'target_user_email' => $user->email,
            'target_user_role' => $user->role->value,
        ]);

        try {
            DB::transaction(function () use ($user): void {
                // Hard delete the user
                $user->delete();
            });

            Log::info('User deleted successfully', [
                'request_id' => $requestId,
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'deleted_user_id' => $user->id,
            ]);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete user', [
                'request_id' => $requestId,
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_user_id' => $user->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to delete user',
                'message' => 'An error occurred while deleting the user.',
            ], 500);
        }
    }
}
