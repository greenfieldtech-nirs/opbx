<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Traits\ValidatesTenantScope;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Users management API controller.
 *
 * Handles CRUD operations for users within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class UsersController extends AbstractApiCrudController
{
    use ValidatesTenantScope;

    /**
     * Get the model class name for this controller.
     */
    protected function getModelClass(): string
    {
        return User::class;
    }

    /**
     * Get the resource class name for transforming models.
     */
    protected function getResourceClass(): string
    {
        return UserResource::class;
    }

    /**
     * Get the allowed filter fields for the index method.
     *
     * @return array<string>
     */
    protected function getAllowedFilters(): array
    {
        return ['role', 'status', 'search'];
    }

    /**
     * Get the allowed sort fields for the index method.
     *
     * @return array<string>
     */
    protected function getAllowedSortFields(): array
    {
        return ['name', 'email', 'created_at', 'role', 'status'];
    }

    /**
     * Get the default sort field for the index method.
     */
    protected function getDefaultSortField(): string
    {
        return 'created_at';
    }

    /**
     * Apply custom filters to the query.
     */
    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        // Apply role filter
        if ($request->has('role')) {
            $role = UserRole::tryFrom($request->input('role'));
            if ($role) {
                $query->withRole($role);
            }
        }

        // Apply status filter
        if ($request->has('status')) {
            $status = UserStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->withStatus($status);
            }
        }

        // Apply search filter
        if ($request->has('search') && $request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Always eager load extension relationship
        $query->with('extension:id,user_id,extension_number');
    }

    /**
     * Hook method called before storing a new model.
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function beforeStore(array $validated, Request $request): array
    {
        // Hash password
        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        return $validated;
    }

    /**
     * Hook method called before updating a model.
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function beforeUpdate(User $model, array $validated, Request $request): array
    {
        // Hash password if provided
        if (isset($validated['password']) && !empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            // Remove password from validated data if not provided
            unset($validated['password']);
        }

        return $validated;
    }

    /**
     * Hook method called before deleting a model.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
        assert($model instanceof User);
        $currentUser = $this->getAuthenticatedUser();

        // Business logic: Cannot delete last owner in organization
        if ($model->role === UserRole::OWNER) {
            $ownerCount = User::forOrganization($currentUser->organization_id)
                ->withRole(UserRole::OWNER)
                ->count();

            if ($ownerCount <= 1) {
                Log::warning('Blocked deletion of last owner', [
                    'request_id' => $this->getRequestId(),
                    'user_id' => $currentUser->id,
                    'organization_id' => $currentUser->organization_id,
                    'target_user_id' => $model->id,
                ]);

                abort(409, 'Cannot delete the last owner in the organization.');
            }
        }
    }

    /**
     * Hook method called after storing a new model.
     */
    protected function afterStore(Model $model, Request $request): void
    {
        // Reload extension relationship
        $model->load('extension:id,user_id,extension_number');
    }

    /**
     * Hook method called after updating a model.
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Reload extension relationship
        $model->load('extension:id,user_id,extension_number');
    }

    /**
     * Store a newly created user.
     */
    public function store(CreateUserRequest $request): JsonResponse
    {
        return parent::store($request);
    }

    /**
     * Update the specified user.
     */
    public function update(UpdateUserRequest $request): JsonResponse
    {
        return parent::update($request);
    }
}