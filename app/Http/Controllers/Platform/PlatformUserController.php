<?php

declare(strict_types=1);

namespace App\Http\Controllers\Platform;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\PlatformCreateUserRequest;
use App\Http\Requests\Platform\PlatformSetManagerRequest;
use App\Http\Requests\Platform\PlatformUpdateUserRequest;
use App\Models\Organization;
use App\Models\User;
use App\Scopes\OrganizationScope;
use App\Services\PlatformAuditService;
use Illuminate\Hashing\HashManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform User Controller
 *
 * Manages cross-tenant user operations for platform managers.
 */
class PlatformUserController extends Controller
{
    /**
     * List all users across organizations.
     */
    public function index(Request $request): JsonResponse
    {
        // Bypass organization scope to get users across all organizations
        $users = OrganizationScope::bypass(function () use ($request) {
            $query = User::query()
                ->with('organization:id,name,slug')
                ->select([
                    'id',
                    'organization_id',
                    'name',
                    'email',
                    'role',
                    'status',
                    'is_platform_manager',
                    'created_at',
                ]);

            // Search
            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Filter by organization
            if ($request->filled('organization_id')) {
                $query->where('organization_id', $request->input('organization_id'));
            }

            // Filter by role
            if ($request->filled('role')) {
                $query->where('role', $request->input('role'));
            }

            // Filter by status
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            // Filter by platform manager
            if ($request->has('is_platform_manager')) {
                $query->where('is_platform_manager', $request->boolean('is_platform_manager'));
            }

            // Sort
            $sortBy = $request->input('sort_by', 'created_at');
            $sortDirection = $request->input('sort_direction', 'desc');
            $allowedSorts = ['name', 'email', 'created_at'];

            if (in_array($sortBy, $allowedSorts, true)) {
                $query->orderBy($sortBy, $sortDirection === 'asc' ? 'asc' : 'desc');
            }

            $perPage = $request->input('per_page', 25);

            return $query->paginate(min($perPage, 100));
        });

        return response()->json($users);
    }

    /**
     * List users for a specific organization.
     */
    public function indexByOrganization(Organization $organization, Request $request): JsonResponse
    {
        $query = User::query()
            ->where('organization_id', $organization->id)
            ->select([
                'id',
                'organization_id',
                'name',
                'email',
                'role',
                'status',
                'is_platform_manager',
                'created_at',
            ]);

        // Search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by role
        if ($request->filled('role')) {
            $query->where('role', $request->input('role'));
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $perPage = $request->input('per_page', 25);
        $users = $query->paginate(min($perPage, 100));

        return response()->json($users);
    }

    /**
     * Show a single user.
     */
    public function show(User $user): JsonResponse
    {
        $user->load('organization:id,name,slug');

        return response()->json([
            'data' => $user,
        ]);
    }

    /**
     * Create a new user in an organization.
     */
    public function store(
        Organization $organization,
        PlatformCreateUserRequest $request,
        HashManager $hash,
        PlatformAuditService $auditService
    ): JsonResponse {
        $validated = $request->validated();

        // Hash password
        $validated['password'] = $hash->make($validated['password']);
        $validated['organization_id'] = $organization->id;
        $validated['status'] = $validated['status'] ?? UserStatus::ACTIVE->value;

        $user = User::create($validated);

        // Audit log
        $auditService->log(
            request: $request,
            action: 'user.created',
            targetOrganizationId: $organization->id,
            targetEntityType: 'User',
            targetEntityId: $user->id,
            afterState: $user->toArray(),
        );

        return response()->json([
            'message' => 'User created successfully.',
            'data' => $user,
        ], 201);
    }

    /**
     * Update a user.
     */
    public function update(
        User $user,
        PlatformUpdateUserRequest $request,
        PlatformAuditService $auditService
    ): JsonResponse {
        $validated = $request->validated();
        $beforeState = $user->only([
            'name', 'email', 'role', 'status', 'phone',
            'street_address', 'city', 'state_province', 'postal_code', 'country',
        ]);

        // Hash password if provided
        if (isset($validated['password'])) {
            $validated['password'] = bcrypt($validated['password']);
        }

        $user->update($validated);

        // Audit log
        $auditService->log(
            request: $request,
            action: 'user.updated',
            targetOrganizationId: $user->organization_id,
            targetEntityType: 'User',
            targetEntityId: $user->id,
            beforeState: $beforeState,
            afterState: $user->fresh()->only([
                'name', 'email', 'role', 'status', 'phone',
                'street_address', 'city', 'state_province', 'postal_code', 'country',
            ]),
        );

        return response()->json([
            'message' => 'User updated successfully.',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Delete a user.
     */
    public function destroy(
        User $user,
        Request $request,
        PlatformAuditService $auditService
    ): JsonResponse {
        $currentUser = $request->user();

        // Cannot delete self
        if ($currentUser->id === $user->id) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        // Check if user is the last owner of their organization
        if ($user->role === UserRole::OWNER) {
            $ownerCount = User::where('organization_id', $user->organization_id)
                ->where('role', UserRole::OWNER)
                ->count();

            if ($ownerCount <= 1) {
                return response()->json([
                    'message' => 'Cannot delete the last owner of an organization.',
                ], 422);
            }
        }

        $beforeState = $user->toArray();
        $organizationId = $user->organization_id;
        $userId = $user->id;

        $user->delete();

        // Audit log
        $auditService->log(
            request: $request,
            action: 'user.deleted',
            targetOrganizationId: $organizationId,
            targetEntityType: 'User',
            targetEntityId: $userId,
            beforeState: $beforeState,
        );

        return response()->json([
            'message' => 'User deleted successfully.',
        ]);
    }

    /**
     * Set or revoke platform manager status for a user.
     */
    public function setPlatformManager(
        User $user,
        PlatformSetManagerRequest $request,
        PlatformAuditService $auditService
    ): JsonResponse {
        $validated = $request->validated();
        $isPlatformManager = $validated['is_platform_manager'];

        // If revoking, check this is not the last platform manager
        if (! $isPlatformManager && $user->is_platform_manager) {
            $pmCount = User::where('is_platform_manager', true)->count();

            if ($pmCount <= 1) {
                return response()->json([
                    'message' => 'Cannot revoke the last platform manager.',
                ], 422);
            }
        }

        $beforeState = ['is_platform_manager' => $user->is_platform_manager];

        // Direct assignment bypasses mass assignment protection
        $user->is_platform_manager = $isPlatformManager;
        $user->save();

        // If revoking, also revoke all tokens
        if (! $isPlatformManager) {
            $user->revokeAllTokens();
        }

        // Audit log
        $auditService->log(
            request: $request,
            action: $isPlatformManager ? 'user.platform_manager.granted' : 'user.platform_manager.revoked',
            targetOrganizationId: $user->organization_id,
            targetEntityType: 'User',
            targetEntityId: $user->id,
            beforeState: $beforeState,
            afterState: ['is_platform_manager' => $isPlatformManager],
        );

        return response()->json([
            'message' => $isPlatformManager
                ? 'Platform manager status granted.'
                : 'Platform manager status revoked. Tokens invalidated.',
            'data' => [
                'id' => $user->id,
                'is_platform_manager' => $isPlatformManager,
            ],
        ]);
    }

    /**
     * Update user password.
     */
    public function updatePassword(
        User $user,
        Request $request,
        HashManager $hash,
        PlatformAuditService $auditService
    ): JsonResponse {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->password = $hash->make($validated['password']);
        $user->save();

        // Audit log
        $auditService->log(
            request: $request,
            action: 'user.password.updated',
            targetOrganizationId: $user->organization_id,
            targetEntityType: 'User',
            targetEntityId: $user->id,
            afterState: ['password_changed' => true],
        );

        return response()->json([
            'message' => 'Password updated successfully.',
            'data' => [
                'id' => $user->id,
            ],
        ]);
    }
}
