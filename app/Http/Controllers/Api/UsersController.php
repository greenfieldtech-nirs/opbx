<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Controllers\Traits\ValidatesTenantScope;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Fallback\ResilientCacheService;
use App\Services\Logging\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

    public function __construct()
    {
        parent::__construct();
        $this->resilientCache = new ResilientCacheService();
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
     * @param User $model User being deleted
     * @param User $currentUser User performing the deletion
     * @throws HttpResponseException If deletion would leave organization without an owner
     */
    private function preventLastOwnerDeletion(User $model, User $currentUser): void
    {
        $lockKey = self::LOCK_KEY_ORG_OWNER_DELETE . $currentUser->organization_id;

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
        // Reload extension relationship
        $model->loadMissing(User::DEFAULT_EXTENSION_FIELDS);

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
        // Reload extension relationship
        $model->loadMissing(User::DEFAULT_EXTENSION_FIELDS);

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
            \Illuminate\Support\Facades\Cache::forget('user.' . $model->id);
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
}
