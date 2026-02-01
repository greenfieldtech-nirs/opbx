<?php

declare(strict_types=1);

namespace App\Services\Validators;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * TenantScopeValidator Service
 *
 * Centralized service for validating tenant scope across models.
 * Eliminates duplicate tenant validation code in controllers.
 */
class TenantScopeValidator
{
    /**
     * Validate that a model belongs to the current user's organization.
     *
     * @param  Model  $model  The model to validate
     * @param  User  $user  The authenticated user
     * @param  string  $action  The action being performed (view, update, delete)
     * @return JsonResponse|null Returns JSON response if validation fails, null if successful
     */
    public function validateTenantAccess(Model $model, User $user, string $action = 'access'): ?JsonResponse
    {
        if ($model->organization_id !== $user->organization_id) {
            $this->logCrossTenantAttempt($model, $user, $action);

            return $this->getNotFoundResponse();
        }

        return null;
    }

    /**
     * Validate tenant access and return with additional context.
     *
     * @param  Model  $model  The model to validate
     * @param  User  $user  The authenticated user
     * @param  string  $action  The action being performed
     * @param  array  $additionalContext  Additional context for logging
     * @return JsonResponse|null Returns JSON response if validation fails, null if successful
     */
    public function validateTenantAccessWithContext(
        Model $model,
        User $user,
        string $action = 'access',
        array $additionalContext = []
    ): ?JsonResponse {
        if ($model->organization_id !== $user->organization_id) {
            $this->logCrossTenantAttempt($model, $user, $action, $additionalContext);

            return $this->getNotFoundResponse();
        }

        return null;
    }

    /**
     * Validate that a model belongs to a specific organization.
     *
     * @param  Model  $model  The model to validate
     * @param  int|string  $organizationId  The expected organization ID
     * @param  string  $action  The action being performed
     * @return JsonResponse|null Returns JSON response if validation fails, null if successful
     */
    public function validateOrganizationId(Model $model, int|string $organizationId, string $action = 'access'): ?JsonResponse
    {
        if ($model->organization_id !== $organizationId) {
            Log::warning('Cross-tenant access attempt', [
                'model_type' => class_basename($model),
                'model_id' => $model->id,
                'expected_organization_id' => $organizationId,
                'actual_organization_id' => $model->organization_id,
                'action' => $action,
            ]);

            return $this->getNotFoundResponse();
        }

        return null;
    }

    /**
     * Check if a model belongs to a specific organization (boolean result).
     *
     * @param  Model  $model  The model to check
     * @param  int|string  $organizationId  The organization ID to check against
     * @return bool True if model belongs to the organization
     */
    public function belongsToOrganization(Model $model, int|string $organizationId): bool
    {
        return $model->organization_id === $organizationId;
    }

    /**
     * Check if a model belongs to the current user's organization.
     *
     * @param  Model  $model  The model to check
     * @param  User  $user  The authenticated user
     * @return bool True if model belongs to the user's organization
     */
    public function belongsToUserOrganization(Model $model, User $user): bool
    {
        return $model->organization_id === $user->organization_id;
    }

    /**
     * Get the standard "Not Found" response for cross-tenant access.
     *
     * @param  string|null  $resourceName  Optional resource name for the error message
     */
    public function getNotFoundResponse(?string $resourceName = null): JsonResponse
    {
        $resource = $resourceName ?? 'Resource';

        return response()->json([
            'error' => 'Not Found',
            'message' => ucfirst($resource).' not found.',
        ], 404);
    }

    /**
     * Log cross-tenant access attempt.
     *
     * @param  Model  $model  The model that was accessed
     * @param  User  $user  The user who attempted access
     * @param  string  $action  The action that was attempted
     * @param  array  $additionalContext  Additional context for logging
     */
    protected function logCrossTenantAttempt(
        Model $model,
        User $user,
        string $action = 'access',
        array $additionalContext = []
    ): void {
        $context = array_merge([
            'model_type' => class_basename($model),
            'model_id' => $model->id,
            'user_id' => $user->id,
            'user_organization_id' => $user->organization_id,
            'target_organization_id' => $model->organization_id,
            'action' => $action,
        ], $additionalContext);

        Log::warning('Cross-tenant access attempt', $context);
    }
}
