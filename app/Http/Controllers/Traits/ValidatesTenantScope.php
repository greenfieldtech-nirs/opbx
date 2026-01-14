<?php

declare(strict_types=1);

namespace App\Http\Controllers\Traits;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Trait for validating tenant scope in custom controller methods.
 *
 * Provides reusable methods for tenant validation in non-CRUD operations
 * where the AbstractApiCrudController's built-in tenant scoping doesn't apply.
 */
trait ValidatesTenantScope
{
    /**
     * Validate that a model belongs to the authenticated user's organization.
     *
     * @param Request $request
     * @param mixed $model The model instance to validate
     * @param string $modelName Human-readable name for logging (e.g., 'user', 'extension')
     * @return JsonResponse|null Returns 404 response if validation fails, null if valid
     */
    protected function validateTenantScope(Request $request, mixed $model, string $modelName = 'resource'): ?JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

        // Check if model has organization_id property
        if (!property_exists($model, 'organization_id')) {
            Log::error("Model does not have organization_id property for tenant validation", [
                'request_id' => $requestId,
                'model_class' => get_class($model),
                'model_id' => $model->id ?? 'unknown',
            ]);

            return response()->json([
                'error' => 'Internal Server Error',
                'message' => 'Invalid model configuration.',
            ], 500);
        }

        // Tenant scope check
        if ($model->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant ' . $modelName . ' access attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_' . $modelName . '_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => ucfirst($modelName) . ' not found.',
            ], 404);
        }

        return null; // Validation passed
    }

    /**
     * Validate that a user belongs to the authenticated user's organization.
     *
     * Convenience method for User model validation.
     *
     * @param Request $request
     * @param User $user
     * @return JsonResponse|null
     */
    protected function validateUserTenantScope(Request $request, User $user): ?JsonResponse
    {
        return $this->validateTenantScope($request, $user, 'user');
    }

    /**
     * Helper method to get the authenticated user.
     *
     * Assumes the controller uses ApiRequestHandler trait.
     */
    abstract protected function getAuthenticatedUser(Request $request): ?User;

    /**
     * Helper method to get the request ID.
     *
     * Assumes the controller uses ApiRequestHandler trait.
     */
    abstract protected function getRequestId(): string;
}