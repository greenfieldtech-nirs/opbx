<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Traits\ValidatesTenantScope;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Requests\User\CreateUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
    use AppliesFilters;
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
     * Get the filter configuration for the index method.
     *
     * @return array<string, array>
     */
    protected function getFilterConfig(): array
    {
        return [
            'role' => [
                'type' => 'enum',
                'enum' => UserRole::class,
                'scope' => 'withRole'
            ],
            'status' => [
                'type' => 'enum',
                'enum' => UserStatus::class,
                'scope' => 'withStatus'
            ],
            'search' => [
                'type' => 'search',
                'scope' => 'search'
            ]
        ];
    }

    /**
     * Apply custom filters to the query.
     */
    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        $this->applyFilters($query, $request, $this->getFilterConfig());

        // Always eager load extension relationship
        $query->with(User::DEFAULT_EXTENSION_FIELDS);
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
    protected function beforeUpdate(Model $model, array $validated, Request $request): array
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
        $model->loadMissing(User::DEFAULT_EXTENSION_FIELDS);
    }

    /**
     * Hook method called after updating a model.
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Reload extension relationship
        $model->loadMissing(User::DEFAULT_EXTENSION_FIELDS);
    }

    /**
     * Transaction not needed - hooks only hash password (data transformation).
     * Simple single-model create operation is already atomic.
     */
    protected function shouldUseTransactionForStore(): bool
    {
        return false;
    }

    /**
     * Transaction not needed - hooks only hash password (data transformation).
     * Simple single-model update operation is already atomic.
     */
    protected function shouldUseTransactionForUpdate(): bool
    {
        return false;
    }

    /**
     * Transaction IS needed - beforeDestroy() performs query to count owners.
     * Must ensure owner count check and delete happen atomically.
     */
    protected function shouldUseTransactionForDestroy(): bool
    {
        return true; // Keep transaction (queries in beforeDestroy hook)
    }

    // No need to override store() and update()
    // Laravel will automatically resolve and validate FormRequest classes
    // based on route-model binding and type hints in the parent controller
}