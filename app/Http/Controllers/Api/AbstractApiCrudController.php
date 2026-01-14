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
     * Hook method to acquire a distributed lock before updating a model.
     * Return null for no locking (default behavior).
     * 
     * @return \Illuminate\Contracts\Cache\Lock|null
     */
    protected function acquireUpdateLock(Model $model, Request $request): ?\Illuminate\Contracts\Cache\Lock
    {
        // Default implementation - no locking
        return null;
    }

    /**
     * Hook method to release a distributed lock after updating a model.
     */
    protected function releaseUpdateLock(?\Illuminate\Contracts\Cache\Lock $lock, Model $model, Request $request): void
    {
        // Default implementation - no action
    }

    /**
     * Hook method called after showing a model (for loading additional relationships).
     */
    protected function afterShow(Model $model, Request $request): void
    {
        // Default implementation - no action
    }

    /**
     * Hook method to build the index query with custom eager loading.
     * Override this to add with(), withCount(), etc.
     */
    protected function buildIndexQuery(Builder $query, Request $request): void
    {
        // Default implementation - no custom query building
    }

    /**
     * Get the default sort order for index method.
     * Override to change from 'asc' to 'desc'.
     */
    protected function getDefaultSortOrder(): string
    {
        return 'asc';
    }

    /**
     * Check if a specific field has changed by comparing old and new values.
     * Handles JSON/array fields specially by using json_encode comparison.
     *
     * @param Model $model The model being updated
     * @param string $key The field name
     * @param mixed $newValue The new value from validated input
     * @return bool True if the field has changed
     */
    protected function isFieldChanged(Model $model, string $key, $newValue): bool
    {
        $oldValue = $model->{$key};

        // Check if this field is cast as an array/json/object type
        $casts = $model->getCasts();
        if (isset($casts[$key]) && in_array($casts[$key], ['array', 'json', 'object', 'collection'], true)) {
            // For JSON/array fields, use json_encode comparison to handle nested structures
            return json_encode($oldValue) !== json_encode($newValue);
        }

        // For other fields (strings, integers, enums, etc.), use loose comparison
        return $oldValue != $newValue;
    }

    /**
     * Get list of changed field names from validated data.
     * Properly handles JSON/array fields and scalar fields.
     *
     * @param Model $model The model being updated
     * @param array $validated The validated input data
     * @return array Array of field names that have changed
     */
    protected function getChangedFields(Model $model, array $validated): array
    {
        $changedFields = [];

        foreach ($validated as $key => $value) {
            if ($this->isFieldChanged($model, $key, $value)) {
                $changedFields[] = $key;
            }
        }

        return $changedFields;
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
        $currentUser = $this->getAuthenticatedUser();

        if ($key === null) {
            $key = $request->route($parameterName);
        }

        // Apply organization scope if the model supports it
        if (method_exists($modelClass, 'scopeForOrganization') && $currentUser) {
            return $modelClass::forOrganization($currentUser->organization_id)->findOrFail($key);
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

        $this->authorize($this->getViewAnyAbility(), $this->getModelClass());

        // Build query
        $modelClass = $this->getModelClass();
        $query = $modelClass::query()->forOrganization($user->organization_id);

        // Apply custom query building (eager loading, etc.)
        $this->buildIndexQuery($query, $request);

        // Apply custom filters
        $this->applyCustomFilters($query, $request);

        // Apply sorting
        $sortField = $request->input('sort_by', $this->getDefaultSortField());
        $sortOrder = $request->input('sort_order', $this->getDefaultSortOrder());

        // Validate sort field
        $allowedSortFields = $this->getAllowedSortFields();
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = $this->getDefaultSortField();
        }

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : $this->getDefaultSortOrder();

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

        $context = $this->getLoggingContext();
        Log::info($this->getPluralResourceKey() . ' list retrieved', array_merge($context, [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $models->total(),
            'per_page' => $perPage,
            'filters' => $filters,
        ]));

        $resourceClass = $this->getResourceClass();
        $collection = $resourceClass::collection($models);
        
        return response()->json([
            'data' => $collection->resolve(),
            'meta' => [
                'current_page' => $models->currentPage(),
                'per_page' => $models->perPage(),
                'total' => $models->total(),
                'last_page' => $models->lastPage(),
                'from' => $models->firstItem(),
                'to' => $models->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created model.
     */
    public function store(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        $this->authorize($this->getCreateAbility(), $this->getModelClass());

        // Get validated data - works with FormRequest objects
        $validated = method_exists($request, 'validated') 
            ? $request->validated() 
            : $request->all();

        $context = $this->getLoggingContext();
        Log::info('Creating new ' . $this->getResourceKey(), array_merge($context, [
            'creator_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
        ]));

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

            $context = $this->getLoggingContext();
            Log::info($this->getResourceKey() . ' created successfully', array_merge($context, [
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey() . '_id' => $model->id,
            ]));

            $resourceClass = $this->getResourceClass();
            return response()->json([
                'message' => $this->getCreateSuccessMessage(),
                'data' => new $resourceClass($model),
            ], 201);
        } catch (\Exception $e) {
            $context = $this->getLoggingContext();
            Log::error($this->getCreateErrorMessage(), array_merge($context, [
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]));

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

        $model = $this->resolveModel($request);
        $this->authorize($this->getViewAbility(), $model);

        // Tenant scope check
        if ($model->organization_id !== $currentUser->organization_id) {
            $context = $this->getLoggingContext();
            Log::warning('Cross-tenant ' . $this->getResourceKey() . ' access attempt', array_merge($context, [
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_' . $this->getResourceKey() . '_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]));

            return response()->json([
                'error' => 'Not Found',
                'message' => ucfirst($this->getResourceKey()) . ' not found.',
            ], 404);
        }

        $context = $this->getLoggingContext();
        Log::info($this->getResourceKey() . ' details retrieved', array_merge($context, [
            'user_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            $this->getResourceKey() . '_id' => $model->id,
        ]));

        // Apply after show hook (for loading additional relationships)
        $this->afterShow($model, $request);

        $resourceClass = $this->getResourceClass();
        return response()->json([
            'data' => new $resourceClass($model),
        ]);
    }

    /**
     * Update the specified model.
     */
    public function update(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        $model = $this->resolveModel($request);

        // Tenant scope check
        if ($model->organization_id !== $currentUser->organization_id) {
            $context = $this->getLoggingContext();
            Log::warning('Cross-tenant ' . $this->getResourceKey() . ' update attempt', array_merge($context, [
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_' . $this->getResourceKey() . '_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]));

            return response()->json([
                'error' => 'Not Found',
                'message' => ucfirst($this->getResourceKey()) . ' not found.',
            ], 404);
        }

        // Get validated data - works with FormRequest objects
        $validated = method_exists($request, 'validated') 
            ? $request->validated() 
            : $request->all();

        // Track changed fields for logging (handles JSON/array fields properly)
        $changedFields = $this->getChangedFields($model, $validated);

        $context = $this->getLoggingContext();
        Log::info('Updating ' . $this->getResourceKey(), array_merge($context, [
            'updater_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            $this->getResourceKey() . '_id' => $model->id,
            'changed_fields' => $changedFields,
        ]));

        // Acquire distributed lock if needed
        $lock = $this->acquireUpdateLock($model, $request);

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

            $context = $this->getLoggingContext();
            Log::info($this->getResourceKey() . ' updated successfully', array_merge($context, [
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey() . '_id' => $model->id,
                'changed_fields' => $changedFields,
            ]));

            $resourceClass = $this->getResourceClass();
            return response()->json([
                'message' => $this->getUpdateSuccessMessage(),
                'data' => new $resourceClass($model),
            ]);
        } catch (\Exception $e) {
            $context = $this->getLoggingContext();
            Log::error($this->getUpdateErrorMessage(), array_merge($context, [
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey() . '_id' => $model->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]));

            return response()->json([
                'error' => $this->getUpdateErrorMessage(),
                'message' => $this->getUpdateUserErrorMessage(),
            ], 500);
        } finally {
            // Always release the lock if acquired
            $this->releaseUpdateLock($lock ?? null, $model, $request);
        }
    }

    /**
     * Remove the specified model.
     */
    public function destroy(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        $model = $this->resolveModel($request);
        $this->authorize($this->getDeleteAbility(), $model);

        // Tenant scope check
        if ($model->organization_id !== $currentUser->organization_id) {
            $context = $this->getLoggingContext();
            Log::warning('Cross-tenant ' . $this->getResourceKey() . ' deletion attempt', array_merge($context, [
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_' . $this->getResourceKey() . '_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]));

            return response()->json([
                'error' => 'Not Found',
                'message' => ucfirst($this->getResourceKey()) . ' not found.',
            ], 404);
        }

        $context = $this->getLoggingContext();
        Log::info('Deleting ' . $this->getResourceKey(), array_merge($context, [
            'deleter_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            $this->getResourceKey() . '_id' => $model->id,
        ]));

        try {
            DB::transaction(function () use ($model, $request): void {
                // Apply before hook
                $this->beforeDestroy($model, $request);

                // Delete model
                $model->delete();

                // Apply after hook
                $this->afterDestroy($model, $request);
            });

            $context = $this->getLoggingContext();
            Log::info($this->getResourceKey() . ' deleted successfully', array_merge($context, [
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey() . '_id' => $model->id,
            ]));

            return response()->json(null, 204);
        } catch (\Exception $e) {
            $context = $this->getLoggingContext();
            Log::error($this->getDeleteErrorMessage(), array_merge($context, [
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey() . '_id' => $model->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]));

            return response()->json([
                'error' => $this->getDeleteErrorMessage(),
                'message' => $this->getDeleteUserErrorMessage(),
            ], 500);
        }
    }
}