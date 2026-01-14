<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\IvrMenuStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Controllers\Traits\LogsOperations;
use App\Http\Requests\StoreIvrMenuRequest;
use App\Http\Requests\UpdateIvrMenuRequest;
use App\Models\CloudonixSettings;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
use App\Services\Cloudonix\CloudonixVoiceService;
use App\ValueObjects\IvrAudioConfig;
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
class IvrMenuController extends Controller
{
    use ApiRequestHandler, LogsOperations;

    public function __construct(
        private CloudonixVoiceService $voiceService
    ) {}

    /**
     * Get available TTS voices for IVR menus.
     *
     * @return JsonResponse
     */
    public function getVoices(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        // Get organization Cloudonix settings
        $organization = $currentUser->organization()->with('cloudonixSettings')->first();

        if (!$organization || !$organization->cloudonixSettings) {
            Log::error('Cloudonix settings missing for organization', [
                'request_id' => $requestId,
                'organization_id' => $currentUser->organization_id
            ]);

            return response()->json([
                'error' => 'Cloudonix settings not configured for your organization.',
                'troubleshooting' => [
                    'Contact your system administrator',
                    'Ensure Cloudonix integration is properly set up for your organization'
                ]
            ], 503);
        }

        $settings = $organization->cloudonixSettings;

        if (!$settings->domain_uuid || !$settings->domain_api_key) {
            Log::error('Incomplete Cloudonix settings', [
                'request_id' => $requestId,
                'organization_id' => $currentUser->organization_id,
                'has_domain_uuid' => !empty($settings->domain_uuid),
                'has_api_key' => !empty($settings->domain_api_key)
            ]);

            return response()->json([
                'error' => 'Cloudonix settings are incomplete.',
                'troubleshooting' => [
                    'Contact your system administrator',
                    'Ensure domain UUID and API key are configured in organization settings'
                ]
            ], 503);
        }

        try {
            // Use service to get voices
            $voices = $this->voiceService->getVoices($settings, $requestId);
            $filters = $this->voiceService->extractFilterOptions($voices);

            return response()->json([
                'data' => $voices,
                'filters' => $filters
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
                    'Update organization settings with new token'
                ];
            } elseif (str_contains($errorMessage, 'timeout') || str_contains($errorMessage, 'connection') || str_contains($errorMessage, 'network')) {
                $statusCode = 502; // Bad Gateway
                $userMessage = 'Unable to connect to Cloudonix API.';
                $troubleshooting = [
                    'Check network connectivity',
                    'Verify Cloudonix API is accessible',
                    'Try again in a few minutes'
                ];
            } else {
                $statusCode = 502; // Bad Gateway
                $userMessage = 'Cloudonix API error: ' . $errorMessage;
                $troubleshooting = [
                    'Check Cloudonix service status',
                    'Contact Cloudonix support if issue persists'
                ];
            }

            Log::error('Cloudonix API error in getVoices', [
                'request_id' => $requestId,
                'organization_id' => $currentUser->organization_id,
                'domain_uuid' => $settings->domain_uuid,
                'error' => $errorMessage,
                'status_code' => $statusCode
            ]);

            return response()->json([
                'error' => $userMessage,
                'troubleshooting' => $troubleshooting
            ], $statusCode);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getVoices', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
                'organization_id' => $currentUser->organization_id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'An unexpected error occurred while fetching voices.',
                'troubleshooting' => [
                    'Contact system administrator',
                    'Check application logs for details'
                ]
            ], 500);
        }
    }





    /**
     * Display a paginated list of IVR menus.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        // Log will be handled by success method below

        // Build query with eager loading
        // For dropdown requests (per_page=100), don't load options to improve performance
        $isDropdownRequest = $request->input('per_page') == 100;

        $query = IvrMenu::query()
            ->forOrganization($user->organization_id);

        if (!$isDropdownRequest) {
            $query->with([
                'options' => function ($query) {
                    $query->select('id', 'ivr_menu_id', 'input_digits', 'description', 'destination_type', 'destination_id', 'priority')
                        ->orderBy('priority', 'asc');
                },
            ])
            ->withCount('options');
        }

        // Apply filters
        if ($request->has('status')) {
            $status = IvrMenuStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if ($request->has('search') && $request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Apply sorting
        $sortField = $request->input('sort', 'created_at');
        $sortOrder = $request->input('order', 'desc');

        // Validate sort field
        $allowedSortFields = ['name', 'status', 'created_at', 'updated_at'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'created_at';
        }

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : 'desc';

        $query->orderBy($sortField, $sortOrder);

        // Paginate
        $perPage = (int) $request->input('per_page', 25);
        $perPage = min(max($perPage, 1), 100); // Clamp between 1 and 100

        $ivrMenus = $query->paginate($perPage);

        $this->logListRetrieved('IVR menu', [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $ivrMenus->total(),
            'per_page' => $perPage,
        ]);

        return response()->json([
            'data' => $ivrMenus->items(),
            'meta' => [
                'current_page' => $ivrMenus->currentPage(),
                'per_page' => $ivrMenus->perPage(),
                'total' => $ivrMenus->total(),
                'last_page' => $ivrMenus->lastPage(),
                'from' => $ivrMenus->firstItem(),
                'to' => $ivrMenus->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created IVR menu.
     *
     * @param StoreIvrMenuRequest $request
     * @return JsonResponse
     */
    public function store(StoreIvrMenuRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $validated = $request->validated();

        // Log will be handled by success/failure methods below

        try {
            $ivrMenu = DB::transaction(function () use ($user, $validated): IvrMenu {
                // Extract options data
                $optionsData = $validated['options'] ?? [];
                unset($validated['options']);

                // Assign to current user's organization
                $validated['organization_id'] = $user->organization_id;

                // Resolve audio configuration using value object
                $audioConfig = IvrAudioConfig::fromRequest($validated, $user);
                $validated = array_merge($validated, $audioConfig->toArray());
                unset($validated['recording_id']); // Clean up temporary field

                // Create IVR menu
                $ivrMenu = IvrMenu::create($validated);

                // Create IVR menu options
                foreach ($optionsData as $optionData) {
                    IvrMenuOption::create([
                        'ivr_menu_id' => $ivrMenu->id,
                        'input_digits' => $optionData['input_digits'],
                        'description' => $optionData['description'] ?? null,
                        'destination_type' => $optionData['destination_type'],
                        'destination_id' => $optionData['destination_id'],
                        'priority' => $optionData['priority'],
                    ]);
                }

                return $ivrMenu;
            });

            // Load relationships
            $ivrMenu->load('options');

            $this->logOperationCompleted('IVR menu', 'creation', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_name' => $ivrMenu->name,
                'options_count' => $ivrMenu->options->count(),
            ]);

            return response()->json([
                'message' => 'IVR menu created successfully.',
                'data' => $ivrMenu,
            ], 201);
        } catch (\Exception $e) {
            $this->logOperationFailed('IVR menu', 'creation', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to create IVR menu',
                'message' => 'An error occurred while creating the IVR menu.',
            ], 500);
        }
    }

    /**
     * Display the specified IVR menu.
     *
     * @param Request $request
     * @param IvrMenu $ivrMenu
     * @return JsonResponse
     */
    public function show(Request $request, IvrMenu $ivrMenu): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        // Tenant scope check
        if ($ivrMenu->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant IVR menu access attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_organization_id' => $ivrMenu->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'IVR menu not found.',
            ], 404);
        }

        // Load relationships
        $ivrMenu->load('options');

        $this->logDetailsRetrieved('IVR menu', [
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'ivr_menu_id' => $ivrMenu->id,
        ]);

        return response()->json([
            'data' => $ivrMenu,
        ]);
    }

    /**
     * Update the specified IVR menu.
     *
     * @param UpdateIvrMenuRequest $request
     * @param IvrMenu $ivrMenu
     * @return JsonResponse
     */
    public function update(UpdateIvrMenuRequest $request, IvrMenu $ivrMenu): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        // Tenant scope check
        if ($ivrMenu->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant IVR menu update attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_organization_id' => $ivrMenu->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'IVR menu not found.',
            ], 404);
        }

        $validated = $request->validated();

        // Log will be handled by success/failure methods below

        try {
            $ivrMenu = DB::transaction(function () use ($ivrMenu, $validated, $user): IvrMenu {
                // Extract options data
                $optionsData = $validated['options'] ?? [];
                unset($validated['options']);

                // Resolve audio configuration using value object
                $audioConfig = IvrAudioConfig::fromRequest($validated, $user);
                $validated = array_merge($validated, $audioConfig->toArray());
                unset($validated['recording_id']); // Clean up temporary field

                // Update IVR menu
                $ivrMenu->update($validated);

                // Delete existing options and create new ones
                $ivrMenu->options()->delete();

                // Create new options
                foreach ($optionsData as $optionData) {
                    IvrMenuOption::create([
                        'ivr_menu_id' => $ivrMenu->id,
                        'input_digits' => $optionData['input_digits'],
                        'description' => $optionData['description'] ?? null,
                        'destination_type' => $optionData['destination_type'],
                        'destination_id' => $optionData['destination_id'],
                        'priority' => $optionData['priority'],
                    ]);
                }

                return $ivrMenu;
            });

            // Load relationships
            $ivrMenu->load('options');

            $this->logOperationCompleted('IVR menu', 'update', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_name' => $ivrMenu->name,
                'options_count' => $ivrMenu->options->count(),
            ]);

            return response()->json([
                'message' => 'IVR menu updated successfully.',
                'data' => $ivrMenu,
            ]);
        } catch (\Exception $e) {
            $this->logOperationFailed('IVR menu', 'update', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to update IVR menu',
                'message' => 'An error occurred while updating the IVR menu.',
            ], 500);
        }
    }

    /**
     * Remove the specified IVR menu.
     *
     * @param Request $request
     * @param IvrMenu $ivrMenu
     * @return JsonResponse
     */
    public function destroy(Request $request, IvrMenu $ivrMenu): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        // Tenant scope check
        if ($ivrMenu->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant IVR menu deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_organization_id' => $ivrMenu->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'IVR menu not found.',
            ], 404);
        }

        // Check for references before deletion
        $referenceChecker = app(\App\Services\ResourceReferenceChecker::class);
        $result = $referenceChecker->checkReferences('ivr_menu', $ivrMenu->id, $ivrMenu->organization_id);

        if ($result['has_references']) {
            // Transform references to maintain backward compatibility with existing response format
            $references = [];
            foreach ($result['references'] as $type => $items) {
                switch ($type) {
                    case 'ivr_menu_options':
                        $references['ivr_menus'] = array_map(fn($item) => [
                            'id' => $item['ivr_menu_id'],
                            'name' => $item['ivr_menu_name'],
                        ], $items);
                        break;
                    case 'ivr_failovers':
                        $references['failover_menus'] = array_map(fn($item) => [
                            'id' => $item['id'],
                            'name' => $item['ivr_menu_name'],
                        ], $items);
                        break;
                    case 'did_numbers':
                        $references['phone_numbers'] = array_map(fn($item) => [
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
        }

        $ivrMenuName = $ivrMenu->name;

        try {
            $ivrMenu->delete();

            $this->logOperationCompleted('IVR menu', 'deletion', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_name' => $ivrMenuName,
            ]);

            return response()->json([
                'message' => 'IVR menu deleted successfully.',
            ]);
        } catch (\Exception $e) {
            $this->logOperationFailed('IVR menu', 'deletion', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to delete IVR menu',
                'message' => 'An error occurred while deleting the IVR menu.',
            ], 500);
        }
    }

    /**
     * Resolve the audio file path from either a direct URL or a recording ID.
     * If recording_id is provided or audio_file_path contains a recording ID, look up the recording and get its playback URL.
     *
     * @param array $data
     * @return void
     */
}