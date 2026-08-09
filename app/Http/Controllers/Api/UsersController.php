<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Controllers\Traits\ValidatesTenantScope;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserEmbedToken;
use App\Scopes\OrganizationScope;
use App\Services\EmbedTokenService;
use App\Services\Fallback\ResilientCacheService;
use App\Services\Logging\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\HttpResponseException;

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
     * Lock key prefix for owner deletion protection.
     * Prevents race condition when deleting the last owner.
     */
    private const LOCK_KEY_ORG_OWNER_DELETE = 'lock:org:owner_delete:';

    /**
     * Lock timeout in seconds for owner deletion.
     */
    private const LOCK_TIMEOUT_SECONDS = 10;

    /**
     * Maximum wait time to acquire lock.
     */
    private const LOCK_WAIT_SECONDS = 5;

    private ResilientCacheService $resilientCache;

    public function __construct(?ResilientCacheService $resilientCache = null)
    {
        $this->resilientCache = $resilientCache ?? new ResilientCacheService;
    }

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
        return ['name', 'email', 'role', 'status', 'created_at', 'updated_at'];
    }

    /**
     * Get the default sort field.
     */
    protected function getDefaultSortField(): string
    {
        return 'created_at';
    }

    /**
     * Get the default sort order.
     */
    protected function getDefaultSortOrder(): string
    {
        return 'desc';
    }

    /**
     * Restrict the user list for supervisors to themselves and their assigned users.
     */
    protected function buildIndexQuery(Builder $query, Request $request): void
    {
        // Eager-load the assigned extension so the users list can render the
        // Extension column (and the detail/edit views) without an N+1.
        $query->with('extension');

        $currentUser = $request->user();

        if ($currentUser && $currentUser->isSupervisor()) {
            $this->restrictToSupervisedUsers($query, $currentUser);
        }
    }

    /**
     * Load relationships needed by UserResource on the show endpoint.
     */
    protected function afterShow(Model $model, Request $request): void
    {
        $model->loadMissing('extension');
    }

    /**
     * Restrict a query to the supervisor and their assigned users.
     */
    private function restrictToSupervisedUsers(Builder $query, User $currentUser): void
    {
        $assignedUserIds = $currentUser->supervisedUsers
            ->pluck('id')
            ->push($currentUser->id)
            ->unique()
            ->values()
            ->toArray();

        $query->whereIn('id', $assignedUserIds);
    }

    /**
     * Hook method called before deleting a model.
     *
     * Implements race condition protection for owner deletion using distributed locking.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
        assert($model instanceof User);
        $currentUser = $this->getAuthenticatedUser();

        // Business logic: Cannot delete last owner in organization
        if ($model->role === UserRole::OWNER) {
            $this->preventLastOwnerDeletion($model, $currentUser);
        }

        // Add audit logging for user deletion (before actual deletion)
        try {
            AuditLogger::logUserDeleted($request, $model->id, $model->email);
        } catch (\Exception $auditException) {
            // Log audit failure but don't fail the operation
            Log::error('Failed to log user deletion audit', [
                'user_id' => $model->id,
                'error' => $auditException->getMessage(),
            ]);
        }
    }

    /**
     * Prevent deletion of the last owner using distributed locking.
     *
     * This method uses distributed locking to prevent race conditions where
     * two concurrent requests could both delete the last owner.
     *
     * @param  User  $model  User being deleted
     * @param  User  $currentUser  User performing the deletion
     *
     * @throws HttpResponseException If deletion would leave organization without an owner
     */
    private function preventLastOwnerDeletion(User $model, User $currentUser): void
    {
        $lockKey = self::LOCK_KEY_ORG_OWNER_DELETE.$currentUser->organization_id;

        $result = $this->resilientCache->lock(
            $lockKey,
            function () use ($model, $currentUser) {
                // Get fresh owner count WITHIN the lock
                $ownerCount = User::forOrganization($currentUser->organization_id)
                    ->withRole(UserRole::OWNER)
                    ->count();

                // Check if deleting this owner would leave organization without an owner
                if ($model->role === UserRole::OWNER && $ownerCount <= 1) {
                    Log::warning('Blocked deletion of last owner', [
                        'request_id' => $this->getRequestId(),
                        'user_id' => $currentUser->id,
                        'organization_id' => $currentUser->organization_id,
                        'target_user_id' => $model->id,
                        'owner_count' => $ownerCount,
                    ]);

                    throw new HttpResponseException(
                        response()->json([
                            'success' => false,
                            'message' => 'Cannot delete the last owner in the organization.',
                            'error_code' => 'LAST_OWNER_DELETE_BLOCKED',
                        ], 409)
                    );
                }

                return true;
            },
            self::LOCK_TIMEOUT_SECONDS,
            self::LOCK_WAIT_SECONDS
        );

        if ($result === null) {
            // Lock acquisition failed
            Log::warning('Failed to acquire lock for owner deletion check', [
                'request_id' => $this->getRequestId(),
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_user_id' => $model->id,
                'lock_key' => $lockKey,
            ]);

            throw new HttpResponseException(
                response()->json([
                    'success' => false,
                    'message' => 'System is busy. Please try again.',
                    'error_code' => 'LOCK_ACQUISITION_FAILED',
                ], 409)
            );
        }
    }

    /**
     * Hook method called after storing a new model.
     */
    protected function afterStore(Model $model, Request $request): void
    {
        // Reload extension relationship (full, so UserResource can serialize it)
        $model->loadMissing('extension');

        // Auto-provision an embedded-dialer token for the new user.
        try {
            assert($model instanceof User);
            $existing = OrganizationScope::bypass(
                fn () => UserEmbedToken::where('user_id', $model->id)->exists()
            );
            if (! $existing) {
                app(EmbedTokenService::class)->generateFor($model);
            }
        } catch (\Exception $embedException) {
            Log::error('Failed to generate embed token for new user', [
                'user_id' => $model->id,
                'error' => $embedException->getMessage(),
            ]);
        }

        // Add audit logging for user creation
        try {
            assert($model instanceof User);
            $currentUser = $this->getAuthenticatedUser();
            AuditLogger::logUserCreated($request, $model);
        } catch (\Exception $auditException) {
            // Log audit failure but don't fail the operation
            Log::error('Failed to log user creation audit', [
                'user_id' => $model->id,
                'error' => $auditException->getMessage(),
            ]);
        }
    }

    /**
     * Hook method called after updating a model.
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Reload extension relationship (full, so UserResource can serialize it)
        $model->loadMissing('extension');

        // Add audit logging for user updates
        try {
            assert($model instanceof User);
            $currentUser = $this->getAuthenticatedUser();

            // Get validated data to check what changed
            $validated = method_exists($request, 'validated')
                ? $request->validated()
                : $request->all();

            // Check if role changed specifically
            if (isset($validated['role']) && $validated['role'] !== $model->role->value) {
                // Role was changed - log this specifically
                AuditLogger::log('user.role_changed', [
                    'target_user_id' => $model->id,
                    'target_user_email' => $model->email,
                    'old_role' => $model->getOriginal('role'),
                    'new_role' => $model->role->value,
                ], AuditLogger::LEVEL_WARNING, $request, $currentUser);
            }

            AuditLogger::logUserUpdated($request, $model);
        } catch (\Exception $auditException) {
            // Log audit failure but don't fail the operation
            Log::error('Failed to log user update audit', [
                'user_id' => $model->id,
                'error' => $auditException->getMessage(),
            ]);
        }
    }

    /**
     * Hook method called after deleting a model.
     */
    protected function afterDelete(Model $model, Request $request): void
    {
        // Clear user from cache
        try {
            Cache::forget('user.'.$model->id);
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }

    /**
     * Determine if the store operation should use database transaction.
     *
     * Simple single-model create operation is already atomic.
     */
    protected function shouldUseTransactionForStore(): bool
    {
        return false;
    }

    /**
     * Determine if the update operation should use database transaction.
     *
     * Simple single-model update operation is already atomic.
     */
    protected function shouldUseTransactionForUpdate(): bool
    {
        return false;
    }

    /**
     * Determine if the destroy operation should use database transaction.
     *
     * Owner deletion check requires atomicity - must use transaction.
     */
    protected function shouldUseTransactionForDestroy(): bool
    {
        return true;
    }

    /**
     * Update user password.
     *
     * Dedicated endpoint for password changes with proper authorization and audit logging.
     *
     * @param  User  $user  The user whose password is being changed
     */
    public function updatePassword(User $user, Request $request): JsonResponse
    {
        $currentUser = $this->getAuthenticatedUser();

        // Check authorization
        if ($currentUser->cannot('updatePassword', $user)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to change this user\'s password.',
                'error_code' => 'UNAUTHORIZED_PASSWORD_CHANGE',
            ], 403);
        }

        // Validate request
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        // Update password
        $user->password = bcrypt($validated['password']);
        $user->save();

        // Audit log with dedicated action for password changes
        try {
            AuditLogger::log('user.password.changed', [
                'target_user_id' => $user->id,
                'target_user_email' => $user->email,
                'changed_by_user_id' => $currentUser->id,
                'changed_by_user_email' => $currentUser->email,
            ], AuditLogger::LEVEL_WARNING, $request, $currentUser);
        } catch (\Exception $auditException) {
            Log::error('Failed to log password change audit', [
                'user_id' => $user->id,
                'error' => $auditException->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
            'data' => [
                'id' => $user->id,
            ],
        ]);
    }
}
