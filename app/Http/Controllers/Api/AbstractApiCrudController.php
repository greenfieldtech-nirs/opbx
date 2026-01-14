<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base controller for CRUD API operations.
 *
 * Provides common CRUD functionality with tenant scoping, authentication,
 * authorization, logging, and error handling patterns.
 */
abstract class AbstractApiCrudController extends Controller
{
    use ApiRequestHandler;

    /**
     * Get the model class name for this controller.
     */
    abstract protected function getModelClass(): string;

    /**
     * Get the resource class name for transforming models.
     */
    abstract protected function getResourceClass(): string;

    /**
     * Get the allowed filter fields for the index method.
     *
     * @return array<string>
     */
    abstract protected function getAllowedFilters(): array;

    /**
     * Get the allowed sort fields for the index method.
     *
     * @return array<string>
     */
    abstract protected function getAllowedSortFields(): array;

    /**
     * Get the default sort field for the index method.
     */
    abstract protected function getDefaultSortField(): string;

    /**
     * Apply custom filters to the query.
     *
     * Override this method to add custom filtering logic.
     */
    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        // Default implementation - no custom filters
    }

    /**
     * Hook method called before storing a new model.
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function beforeStore(array $validated, Request $request): array
    {
        return $validated;
    }

    /**
     * Hook method called after storing a new model.
     */
    protected function afterStore(Model $model, Request $request): void
    {
        // Default implementation - no action
    }

    /**
     * Hook method called before updating a model.
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function beforeUpdate(Model $model, array $validated, Request $request): array
    {
        return $validated;
    }

    /**
     * Hook method called after updating a model.
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        // Default implementation - no action
    }

    /**
     * Hook method called before deleting a model.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
        // Default implementation - no action
    }

    /**
     * Hook method called after deleting a model.
     */
    protected function afterDestroy(Model $model, Request $request): void
    {
        // Default implementation - no action
    }

    /**
     * Get the model instance for authorization and scoping.
     *
     * Override for custom model resolution logic.
     */
    protected function resolveModel(Request $request, ?string $key = null): Model
    {
        $modelClass = $this->getModelClass();
        $parameterName = $this->getRouteParameterName();

        if ($key === null) {
            $key = $request->route($parameterName);
        }

        return $modelClass::findOrFail($key);
    }

    /**
     * Get the route parameter name for model binding.
     *
     * Defaults to the lowercase model class name without namespace.
     */
    protected function getRouteParameterName(): string
    {
        $modelClass = $this->getModelClass();
        $className = class_basename($modelClass);

        return strtolower($className);
    }

    /**
     * Get the policy ability name for viewAny.
     *
     * Override if different from default.
     */
    protected function getViewAnyAbility(): string
    {
        return 'viewAny';
    }

    /**
     * Get the policy ability name for view.
     *
     * Override if different from default.
     */
    protected function getViewAbility(): string
    {
        return 'view';
    }

    /**
     * Get the policy ability name for create.
     *
     * Override if different from default.
     */
    protected function getCreateAbility(): string
    {
        return 'create';
    }

    /**
     * Get the policy ability name for update.
     *
     * Override if different from default.
     */
    protected function getUpdateAbility(): string
    {
        return 'update';
    }

    /**
     * Get the policy ability name for delete.
     *
     * Override if different from default.
     */
    protected function getDeleteAbility(): string
    {
        return 'delete';
    }

    /**
     * Get the success message for create operations.
     */
    protected function getCreateSuccessMessage(): string
    {
        $modelClass = $this->getModelClass();
        $modelName = strtolower(class_basename($modelClass));

        return ucfirst($modelName) . ' created successfully.';
    }

    /**
     * Get the success message for update operations.
     */
    protected function getUpdateSuccessMessage(): string
    {
        $modelClass = $this->getModelClass();
        $modelName = strtolower(class_basename($modelClass));

        return ucfirst($modelName) . ' updated successfully.';
    }

    /**
     * Get the error message for create operations.
     */
    protected function getCreateErrorMessage(): string
    {
        $modelClass = $this->getModelClass();
        $modelName = strtolower(class_basename($modelClass));

        return 'Failed to create ' . $modelName;
    }

    /**
     * Get the error message for update operations.
     */
    protected function getUpdateErrorMessage(): string
    {
        $modelClass = $this->getModelClass();
        $modelName = strtolower(class_basename($modelClass));

        return 'Failed to update ' . $modelName;
    }

    /**
     * Get the error message for delete operations.
     */
    protected function getDeleteErrorMessage(): string
    {
        $modelClass = $this->getModelClass();
        $modelName = strtolower(class_basename($modelClass));

        return 'Failed to delete ' . $modelName;
    }

    /**
     * Get the user-friendly error message for create operations.
     */
    protected function getCreateUserErrorMessage(): string
    {
        $modelClass = $this->getModelClass();
        $modelName = strtolower(class_basename($modelClass));

        return 'An error occurred while creating the ' . $modelName . '.';
    }

    /**
     * Get the user-friendly error message for update operations.
     */
    protected function getUpdateUserErrorMessage(): string
    {
        $modelClass = $this->getModelClass();
        $modelName = strtolower(class_basename($modelClass));

        return 'An error occurred while updating the ' . $modelName . '.';
    }

    /**
     * Get the user-friendly error message for delete operations.
     */
    protected function getDeleteUserErrorMessage(): string
    {
        $modelClass = $this->getModelClass();
        $modelName = strtolower(class_basename($modelClass));

        return 'An error occurred while deleting the ' . $modelName . '.';
    }

    /**
     * Get the resource key for JSON responses.
     */
    protected function getResourceKey(): string
    {
        $modelClass = $this->getModelClass();
        $modelName = strtolower(class_basename($modelClass));

        return $modelName;
    }

    /**
     * Get the plural resource key for JSON responses.
     */
    protected function getPluralResourceKey(): string
    {
        return $this->getResourceKey() . 's';
    }

    /**
     * Display a paginated list of models.
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $this->authorize($this->getViewAnyAbility(), $this->getModelClass());

        // Build query
        $modelClass = $this->getModelClass();
        $query = $modelClass::query()->forOrganization($user->organization_id);

        // Apply custom filters
        $this->applyCustomFilters($query, $request);

        // Apply sorting
        $sortField = $request->input('sort_by', $this->getDefaultSortField());
        $sortOrder = $request->input('sort_order', 'asc');

        // Validate sort field
        $allowedSortFields = $this->getAllowedSortFields();
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = $this->getDefaultSortField();
        }

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : 'asc';

        $query->orderBy($sortField, $sortOrder);

        // Paginate
        $perPage = (int) $request->input('per_page', 20);
        $perPage = min(max($perPage, 1), 100); // Clamp between 1 and 100

        $models = $query->paginate($perPage);

        // Build filters array for logging
        $filters = [];
        foreach ($this->getAllowedFilters() as $filter) {
            if ($request->has($filter)) {
                $filters[$filter] = $request->input($filter);
            }
        }

        Log::info($this->getPluralResourceKey() . ' list retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $models->total(),
            'per_page' => $perPage,
            'filters' => $filters,
        ]);

        $resourceClass = $this->getResourceClass();
        return response()->json([
            $this->getPluralResourceKey() => $resourceClass::collection($models),
        ]);
    }

    /**
     * Store a newly created model.
     */
    public function store(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $this->authorize($this->getCreateAbility(), $this->getModelClass());

        $validated = $request->validated();

        Log::info('Creating new ' . $this->getResourceKey(), [
            'request_id' => $requestId,
            'creator_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
        ]);

        try {
            $model = DB::transaction(function () use ($currentUser, $validated, $request): Model {
                // Apply before hook
                $validated = $this->beforeStore($validated, $request);

                // Assign to current user's organization
                $validated['organization_id'] = $currentUser->organization_id;

                // Create model
                $modelClass = $this->getModelClass();
                $model = $modelClass::create($validated);

                // Apply after hook
                $this->afterStore($model, $request);

                return $model;
            });

            Log::info($this->getResourceKey() . ' created successfully', [
                'request_id' => $requestId,
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey() . '_id' => $model->id,
            ]);

            $resourceClass = $this->getResourceClass();
            return response()->json([
                'message' => $this->getCreateSuccessMessage(),
                $this->getResourceKey() => new $resourceClass($model),
            ], 201);
        } catch (\Exception $e) {
            Log::error($this->getCreateErrorMessage(), [
                'request_id' => $requestId,
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => $this->getCreateErrorMessage(),
                'message' => $this->getCreateUserErrorMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified model.
     */
    public function show(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $model = $this->resolveModel($request);
        $this->authorize($this->getViewAbility(), $model);

        // Tenant scope check
        if ($model->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant ' . $this->getResourceKey() . ' access attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_' . $this->getResourceKey() . '_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => ucfirst($this->getResourceKey()) . ' not found.',
            ], 404);
        }

        Log::info($this->getResourceKey() . ' details retrieved', [
            'request_id' => $requestId,
            'user_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            $this->getResourceKey() . '_id' => $model->id,
        ]);

        $resourceClass = $this->getResourceClass();
        return response()->json([
            $this->getResourceKey() => new $resourceClass($model),
        ]);
    }

    /**
     * Update the specified model.
     */
    public function update(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $model = $this->resolveModel($request);

        // Tenant scope check
        if ($model->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant ' . $this->getResourceKey() . ' update attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_' . $this->getResourceKey() . '_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => ucfirst($this->getResourceKey()) . ' not found.',
            ], 404);
        }

        $validated = $request->validated();

        // Track changed fields for logging
        $changedFields = [];
        foreach ($validated as $key => $value) {
            if ($model->{$key} != $value) {
                $changedFields[] = $key;
            }
        }

        Log::info('Updating ' . $this->getResourceKey(), [
            'request_id' => $requestId,
            'updater_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            $this->getResourceKey() . '_id' => $model->id,
            'changed_fields' => $changedFields,
        ]);

        try {
            DB::transaction(function () use ($model, $validated, $request): void {
                // Apply before hook
                $validated = $this->beforeUpdate($model, $validated, $request);

                // Update model
                $model->update($validated);

                // Apply after hook
                $this->afterUpdate($model, $request);
            });

            // Reload model
            $model->refresh();

            Log::info($this->getResourceKey() . ' updated successfully', [
                'request_id' => $requestId,
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey() . '_id' => $model->id,
                'changed_fields' => $changedFields,
            ]);

            $resourceClass = $this->getResourceClass();
            return response()->json([
                'message' => $this->getUpdateSuccessMessage(),
                $this->getResourceKey() => new $resourceClass($model),
            ]);
        } catch (\Exception $e) {
            Log::error($this->getUpdateErrorMessage(), [
                'request_id' => $requestId,
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey() . '_id' => $model->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => $this->getUpdateErrorMessage(),
                'message' => $this->getUpdateUserErrorMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified model.
     */
    public function destroy(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $model = $this->resolveModel($request);
        $this->authorize($this->getDeleteAbility(), $model);

        // Tenant scope check
        if ($model->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant ' . $this->getResourceKey() . ' deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_' . $this->getResourceKey() . '_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => ucfirst($this->getResourceKey()) . ' not found.',
            ], 404);
        }

        Log::info('Deleting ' . $this->getResourceKey(), [
            'request_id' => $requestId,
            'deleter_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            $this->getResourceKey() . '_id' => $model->id,
        ]);

        try {
            DB::transaction(function () use ($model, $request): void {
                // Apply before hook
                $this->beforeDestroy($model, $request);

                // Delete model
                $model->delete();

                // Apply after hook
                $this->afterDestroy($model, $request);
            });

            Log::info($this->getResourceKey() . ' deleted successfully', [
                'request_id' => $requestId,
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey() . '_id' => $model->id,
            ]);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error($this->getDeleteErrorMessage(), [
                'request_id' => $requestId,
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey() . '_id' => $model->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => $this->getDeleteErrorMessage(),
                'message' => $this->getDeleteUserErrorMessage(),
            ], 500);
        }
    }
}