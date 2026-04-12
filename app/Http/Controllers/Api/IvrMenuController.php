<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\IvrMenuStatus;
use App\Exceptions\ResourceInUseException;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Resources\IvrMenuResource;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
use App\Services\Cloudonix\CloudonixVoiceService;
use App\ValueObjects\IvrAudioConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * IVR Menus management API controller.
 *
 * Handles CRUD operations for IVR menus within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class IvrMenuController extends AbstractApiCrudController
{
    use ApiRequestHandler, AppliesFilters;

    /**
     * Options data extracted from request during beforeStore/beforeUpdate hooks.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $optionsData = [];

    public function __construct(
        private CloudonixVoiceService $voiceService
    ) {}

    /**
     * Get the model class name for this controller.
     */
    protected function getModelClass(): string
    {
        return IvrMenu::class;
    }

    /**
     * Get the route parameter name for model binding.
     *
     * Override to match route parameter 'ivrMenu' instead of 'ivr_menu'.
     */
    protected function getRouteParameterName(): string
    {
        return 'ivrMenu';
    }

    /**
     * Get the resource class name for transforming models.
     */
    protected function getResourceClass(): string
    {
        return IvrMenuResource::class;
    }

    /**
     * Get the allowed filter fields for the index method.
     *
     * @return array<string>
     */
    protected function getAllowedFilters(): array
    {
        return ['status', 'search'];
    }

    /**
     * Get the allowed sort fields for the index method.
     *
     * @return array<string>
     */
    protected function getAllowedSortFields(): array
    {
        return ['name', 'status', 'created_at', 'updated_at'];
    }

    /**
     * Get the default sort field for the index method.
     */
    protected function getDefaultSortField(): string
    {
        return 'created_at';
    }

    /**
     * Get the default sort order for index method.
     */
    protected function getDefaultSortOrder(): string
    {
        return 'desc';
    }

    /**
     * Get the filter configuration for the index method.
     */
    protected function getFilterConfig(): array
    {
        return [
            'status' => [
                'type' => 'enum',
                'enum' => IvrMenuStatus::class,
                'scope' => 'withStatus',
            ],
            'search' => [
                'type' => 'search',
                'scope' => 'search',
            ],
        ];
    }

    /**
     * Apply custom filters to the query.
     */
    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        // Filters are applied via AppliesFilters trait
    }

    /**
     * Build the index query with eager loading.
     */
    protected function buildIndexQuery(Builder $query, Request $request): void
    {
        // For dropdown requests (per_page=100), don't load options to improve performance
        $isDropdownRequest = $request->input('per_page') == 100;

        if (! $isDropdownRequest) {
            $query->with([
                'options' => function ($query) {
                    $query->select('id', 'ivr_menu_id', 'input_digits', 'description', 'destination_type', 'destination_id', 'priority')
                        ->orderBy('priority', 'asc');
                },
            ])
                ->withCount('options');
        }
    }

    /**
     * Hook method called after showing a model (for loading additional relationships).
     */
    protected function afterShow(Model $model, Request $request): void
    {
        /** @var IvrMenu $model */
        $model->load('options');
    }

    /**
     * Hook called before storing a new IVR menu.
     *
     * Extracts options data and resolves audio configuration.
     */
    protected function beforeStore(array $validated, Request $request): array
    {
        $user = $this->getAuthenticatedUser();

        // Extract options data for later creation
        $this->optionsData = $validated['options'] ?? [];
        unset($validated['options']);

        // Resolve audio configuration using value object
        $audioConfig = IvrAudioConfig::fromRequest($validated, $user);
        $validated = array_merge($validated, $audioConfig->toArray());
        unset($validated['recording_id']); // Clean up temporary field

        return $validated;
    }

    /**
     * Hook called after storing a new IVR menu.
     *
     * Creates IVR menu options.
     */
    protected function afterStore(Model $model, Request $request): void
    {
        /** @var IvrMenu $model */

        // Create IVR menu options
        foreach ($this->optionsData as $optionData) {
            IvrMenuOption::create([
                'ivr_menu_id' => $model->id,
                'input_digits' => $optionData['input_digits'],
                'description' => $optionData['description'] ?? null,
                'destination_type' => $optionData['destination_type'],
                'destination_id' => $optionData['destination_id'],
                'priority' => $optionData['priority'],
            ]);
        }

        // Load relationships for response
        $model->load('options');

        // Clear stored options data
        $this->optionsData = [];
    }

    /**
     * Hook called before updating an IVR menu.
     *
     * Extracts options data and resolves audio configuration.
     */
    protected function beforeUpdate(Model $model, array $validated, Request $request): array
    {
        $user = $this->getAuthenticatedUser();

        // Extract options data for later recreation
        $this->optionsData = $validated['options'] ?? [];
        unset($validated['options']);

        // Resolve audio configuration using value object
        $audioConfig = IvrAudioConfig::fromRequest($validated, $user);
        $validated = array_merge($validated, $audioConfig->toArray());
        unset($validated['recording_id']); // Clean up temporary field

        return $validated;
    }

    /**
     * Hook called after updating an IVR menu.
     *
     * Deletes existing options and creates new ones.
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        /** @var IvrMenu $model */

        // Delete existing options and create new ones
        $model->options()->delete();

        foreach ($this->optionsData as $optionData) {
            IvrMenuOption::create([
                'ivr_menu_id' => $model->id,
                'input_digits' => $optionData['input_digits'],
                'description' => $optionData['description'] ?? null,
                'destination_type' => $optionData['destination_type'],
                'destination_id' => $optionData['destination_id'],
                'priority' => $optionData['priority'],
            ]);
        }

        // Load relationships for response
        $model->load('options');

        // Clear stored options data
        $this->optionsData = [];
    }

    /**
     * Display a paginated list of IVR menus.
     *
     * Override to skip authorization (no policy defined for IvrMenu).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

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
        if (! in_array($sortField, $allowedSortFields, true)) {
            $sortField = $this->getDefaultSortField();
        }

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : $this->getDefaultSortOrder();

        $query->orderBy($sortField, $sortOrder);

        // Paginate
        $perPage = (int) $request->input('per_page', 20);
        $perPage = min(max($perPage, 1), 100);

        $models = $query->paginate($perPage);

        // Build filters array for logging
        $filters = [];
        foreach ($this->getAllowedFilters() as $filter) {
            if ($request->has($filter)) {
                $filters[$filter] = $request->input($filter);
            }
        }

        $this->logListRetrieved($this->getResourceKey(), [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $models->total(),
            'per_page' => $perPage,
            'filters' => $filters,
        ]);

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
     * Store a newly created IVR menu.
     *
     * Override to skip authorization (no policy defined for IvrMenu).
     */
    public function store(Request $request): JsonResponse
    {
        $currentUser = $this->getAuthenticatedUser();

        // Get validated data - works with FormRequest objects
        $validated = method_exists($request, 'validated')
            ? $request->validated()
            : $request->all();

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

            $this->logOperationCompleted($this->getResourceKey(), 'creation', [
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey().'_id' => $model->id,
            ]);

            $resourceClass = $this->getResourceClass();

            return response()->json([
                'message' => $this->getCreateSuccessMessage(),
                'data' => new $resourceClass($model),
            ], 201);
        } catch (\Exception $e) {
            $this->logOperationFailed($this->getResourceKey(), 'creation', [
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                $this->getCreateUserErrorMessage(),
                500,
                'CREATION_ERROR',
                ['resource' => $this->getResourceKey()]
            );
        }
    }

    /**
     * Display the specified IVR menu.
     *
     * Override to skip authorization (no policy defined for IvrMenu).
     */
    public function show(Request $request): JsonResponse
    {
        $currentUser = $this->getAuthenticatedUser();

        $model = $this->resolveModel($request);

        // Tenant scope check
        if ($model->organization_id !== $currentUser->organization_id) {
            $context = $this->getLoggingContext();
            Log::warning('Cross-tenant '.$this->getResourceKey().' access attempt', array_merge($context, [
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_'.$this->getResourceKey().'_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]));

            return response()->json([
                'error' => 'Not Found',
                'message' => ucfirst($this->getResourceKey()).' not found.',
            ], 404);
        }

        $this->logDetailsRetrieved($this->getResourceKey(), [
            'user_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            $this->getResourceKey().'_id' => $model->id,
        ]);

        // Apply after show hook (for loading additional relationships)
        $this->afterShow($model, $request);

        $resourceClass = $this->getResourceClass();

        return response()->json([
            'data' => new $resourceClass($model),
        ]);
    }

    /**
     * Update the specified IVR menu.
     *
     * Override to skip authorization (no policy defined for IvrMenu).
     */
    public function update(Request $request): JsonResponse
    {
        $currentUser = $this->getAuthenticatedUser();

        $model = $this->resolveModel($request);

        // Tenant scope check
        if ($model->organization_id !== $currentUser->organization_id) {
            $context = $this->getLoggingContext();
            Log::warning('Cross-tenant '.$this->getResourceKey().' update attempt', array_merge($context, [
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_'.$this->getResourceKey().'_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]));

            return response()->json([
                'error' => 'Not Found',
                'message' => ucfirst($this->getResourceKey()).' not found.',
            ], 404);
        }

        // Get validated data - works with FormRequest objects
        $validated = method_exists($request, 'validated')
            ? $request->validated()
            : $request->all();

        // Track changed fields for logging (handles JSON/array fields properly)
        $changedFields = $this->getChangedFields($model, $validated);

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

            $this->logOperationCompleted($this->getResourceKey(), 'update', [
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey().'_id' => $model->id,
                'changed_fields' => $changedFields,
            ]);

            $resourceClass = $this->getResourceClass();

            return response()->json([
                'message' => $this->getUpdateSuccessMessage(),
                'data' => new $resourceClass($model),
            ]);
        } catch (\Exception $e) {
            $this->logOperationFailed($this->getResourceKey(), 'update', [
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey().'_id' => $model->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                $this->getUpdateUserErrorMessage(),
                500,
                'UPDATE_ERROR',
                ['resource' => $this->getResourceKey()]
            );
        }
    }

    /**
     * Remove the specified IVR menu.
     *
     * Override to handle ResourceInUseException and return proper response format.
     */
    public function destroy(Request $request): JsonResponse
    {
        $currentUser = $this->getAuthenticatedUser();
        $model = $this->resolveModel($request);

        // Tenant scope check
        if ($model->organization_id !== $currentUser->organization_id) {
            $context = $this->getLoggingContext();
            Log::warning('Cross-tenant '.$this->getResourceKey().' deletion attempt', array_merge($context, [
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_'.$this->getResourceKey().'_id' => $model->id,
                'target_organization_id' => $model->organization_id,
            ]));

            return response()->json([
                'error' => 'Not Found',
                'message' => ucfirst($this->getResourceKey()).' not found.',
            ], 404);
        }

        try {
            DB::transaction(function () use ($model, $request): void {
                // Apply before hook (checks for references)
                $this->beforeDestroy($model, $request);

                // Delete model
                $model->delete();

                // Apply after hook
                $this->afterDestroy($model, $request);
            });

            $this->logOperationCompleted($this->getResourceKey(), 'deletion', [
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey().'_id' => $model->id,
            ]);

            // Return 200 with message (matching original controller behavior)
            return response()->json([
                'message' => 'IVR menu deleted successfully.',
            ]);
        } catch (ResourceInUseException $e) {
            // Transform references to maintain backward compatibility
            $references = [];
            foreach ($e->references as $type => $items) {
                switch ($type) {
                    case 'ivr_menu_options':
                        $references['ivr_menus'] = array_map(fn ($item) => [
                            'id' => $item['ivr_menu_id'],
                            'name' => $item['ivr_menu_name'],
                        ], $items);
                        break;
                    case 'ivr_failovers':
                        $references['failover_menus'] = array_map(fn ($item) => [
                            'id' => $item['id'],
                            'name' => $item['ivr_menu_name'],
                        ], $items);
                        break;
                    case 'did_numbers':
                        $references['phone_numbers'] = array_map(fn ($item) => [
                            'id' => $item['id'],
                            'phone_number' => $item['phone_number'],
                        ], $items);
                        break;
                }
            }

            return response()->json([
                'error' => 'Cannot delete IVR menu',
                'message' => 'This IVR menu is being used and cannot be deleted. Please remove all references first.',
                'references' => $references,
            ], 409);
        } catch (\Exception $e) {
            $this->logOperationFailed($this->getResourceKey(), 'deletion', [
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                $this->getResourceKey().'_id' => $model->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse(
                $this->getDeleteUserErrorMessage(),
                500,
                'DELETE_ERROR',
                ['resource' => $this->getResourceKey()]
            );
        }
    }

    /**
     * Hook called before deleting an IVR menu.
     *
     * Checks for references before deletion.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
        /** @var IvrMenu $model */
        $this->checkResourceReferencesBeforeDelete('ivr_menu', $model->id, $model->organization_id);
    }

    /**
     * Get available TTS voices for IVR menus.
     */
    public function getVoices(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        // Get organization Cloudonix settings
        $organization = $currentUser->organization()->with('cloudonixSettings')->first();

        if (! $organization || ! $organization->cloudonixSettings) {
            Log::error('Cloudonix settings missing for organization', [
                'request_id' => $requestId,
                'organization_id' => $currentUser->organization_id,
            ]);

            return response()->json([
                'error' => 'Cloudonix settings not configured for your organization.',
                'troubleshooting' => [
                    'Contact your system administrator',
                    'Ensure Cloudonix integration is properly set up for your organization',
                ],
            ], 503);
        }

        $settings = $organization->cloudonixSettings;

        if (! $settings->domain_uuid || ! $settings->domain_api_key) {
            Log::error('Incomplete Cloudonix settings', [
                'request_id' => $requestId,
                'organization_id' => $currentUser->organization_id,
                'has_domain_uuid' => ! empty($settings->domain_uuid),
                'has_api_key' => ! empty($settings->domain_api_key),
            ]);

            return response()->json([
                'error' => 'Cloudonix settings are incomplete.',
                'troubleshooting' => [
                    'Contact your system administrator',
                    'Ensure domain UUID and API key are configured in organization settings',
                ],
            ], 503);
        }

        try {
            // Use service to get voices
            $voices = $this->voiceService->getVoices($settings, $requestId);
            $filters = $this->voiceService->extractFilterOptions($voices);

            return response()->json([
                'data' => $voices,
                'filters' => $filters,
            ]);

        } catch (\RuntimeException $e) {
            // Handle specific CloudonixClient errors
            $errorMessage = $e->getMessage();

            if (str_contains($errorMessage, 'token') || str_contains($errorMessage, 'unauthorized') || str_contains($errorMessage, 'authentication')) {
                $statusCode = 401; // Unauthorized
                $userMessage = 'Authentication failed with Cloudonix API.';
                $troubleshooting = [
                    'Check API token validity',
                    'Regenerate API key in Cloudonix dashboard',
                    'Update organization settings with new token',
                ];
            } elseif (str_contains($errorMessage, 'timeout') || str_contains($errorMessage, 'connection') || str_contains($errorMessage, 'network')) {
                $statusCode = 502; // Bad Gateway
                $userMessage = 'Unable to connect to Cloudonix API.';
                $troubleshooting = [
                    'Check network connectivity',
                    'Verify Cloudonix API is accessible',
                    'Try again in a few minutes',
                ];
            } else {
                $statusCode = 502; // Bad Gateway
                $userMessage = 'Cloudonix API error: '.$errorMessage;
                $troubleshooting = [
                    'Check Cloudonix service status',
                    'Contact Cloudonix support if issue persists',
                ];
            }

            Log::error('Cloudonix API error in getVoices', [
                'request_id' => $requestId,
                'organization_id' => $currentUser->organization_id,
                'domain_uuid' => $settings->domain_uuid,
                'error' => $errorMessage,
                'status_code' => $statusCode,
            ]);

            return response()->json([
                'error' => $userMessage,
                'troubleshooting' => $troubleshooting,
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getVoices', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'organization_id' => $currentUser->organization_id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'An unexpected error occurred while fetching voices.',
                'troubleshooting' => [
                    'Contact system administrator',
                    'Check application logs for details',
                ],
            ], 500);
        }
    }

    /**
     * Toggle the status of an IVR menu.
     */
    public function toggleStatus(Request $request, IvrMenu $ivrMenu): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        // Tenant scope check
        if ($ivrMenu->organization_id !== $user->organization_id) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'IVR menu not found.',
            ], 404);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', 'in:active,inactive'],
        ]);

        $newStatus = $validated['status'];
        $oldStatus = $ivrMenu->status;

        try {
            $ivrMenu->update(['status' => $newStatus]);

            $this->logOperationCompleted('IVR menu', 'status toggle', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_name' => $ivrMenu->name,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            return response()->json([
                'message' => 'IVR menu status updated successfully.',
                'data' => [
                    'id' => $ivrMenu->id,
                    'status' => $ivrMenu->status,
                ],
            ]);
        } catch (\Exception $e) {
            $this->logOperationFailed('IVR menu', 'status toggle', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to update IVR menu status',
                'message' => 'An error occurred while updating the IVR menu status.',
            ], 500);
        }
    }
}
