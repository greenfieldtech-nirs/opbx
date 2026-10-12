<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\IvrMenuStatus;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Requests\StoreIvrMenuRequest;
use App\Http\Requests\ToggleIvrMenuStatusRequest;
use App\Http\Requests\UpdateIvrMenuRequest;
use App\Http\Resources\IvrMenuResource;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
use App\Services\Cloudonix\CloudonixVoiceService;
use App\ValueObjects\IvrAudioConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    protected function getStoreRequestClass(): ?string
    {
        return StoreIvrMenuRequest::class;
    }

    protected function getUpdateRequestClass(): ?string
    {
        return UpdateIvrMenuRequest::class;
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
        return ['status', 'search', 'unprovisioned'];
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
            'unprovisioned' => [
                'type' => 'column',
                'scope' => 'unprovisioned',
                'require_filled' => true,
            ],
        ];
    }

    /**
     * Apply custom filters to the query.
     */
    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        $this->applyFilters($query, $request, $this->getFilterConfig());
    }

    /**
     * Build the index query with eager loading.
     */
    protected function buildIndexQuery(Builder $query, Request $request): void
    {
        // For dropdown requests (per_page=100), don't load options to improve performance
        $isDropdownRequest = $request->input('per_page') == 100;

        $query->withExists(['options as has_unavailable_option' => fn ($q) => $q->where('destination_type', IvrMenu::UNPROVISIONED_VALUE)]);

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
                'destination_id' => $optionData['destination_id'] ?? 0,
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
                'destination_id' => $optionData['destination_id'] ?? 0,
                'priority' => $optionData['priority'],
            ]);
        }

        // Load relationships for response
        $model->load('options');

        // Clear stored options data
        $this->optionsData = [];
    }

    /**
     * Hook called before deleting an IVR menu.
     *
     * Checks for references before deletion.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
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
    public function toggleStatus(ToggleIvrMenuStatusRequest $request, IvrMenu $ivrMenu): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        // Tenant scope check
        if ($ivrMenu->organization_id !== $user->organization_id) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'IVR menu not found.',
            ], 404);
        }

        $validated = $request->validated();

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
