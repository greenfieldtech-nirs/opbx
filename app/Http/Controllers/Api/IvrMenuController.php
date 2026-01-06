<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\IvrMenuStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\StoreIvrMenuRequest;
use App\Http\Requests\UpdateIvrMenuRequest;
use App\Models\IvrMenu;
use App\Models\IvrMenuOption;
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
    use ApiRequestHandler;

    /**
     * Get available TTS voices for IVR menus.
     *
     * @return JsonResponse
     */
    public function getVoices(): JsonResponse
    {
        // Return common TTS voices supported by Cloudonix
        // In production, this could be fetched from Cloudonix API or cached from CloudonixSettings
        $voices = [
            [
                'id' => 'en-US-Neural2-A',
                'name' => 'English US - Female (Neural)',
                'language' => 'en-US',
                'gender' => 'female',
                'premium' => false,
            ],
            [
                'id' => 'en-US-Neural2-D',
                'name' => 'English US - Male (Neural)',
                'language' => 'en-US',
                'gender' => 'male',
                'premium' => false,
            ],
            [
                'id' => 'en-GB-Neural2-A',
                'name' => 'English UK - Female (Neural)',
                'language' => 'en-GB',
                'gender' => 'female',
                'premium' => true,
            ],
            [
                'id' => 'en-GB-Neural2-D',
                'name' => 'English UK - Male (Neural)',
                'language' => 'en-GB',
                'gender' => 'male',
                'premium' => true,
            ],
            [
                'id' => 'es-ES-Neural2-A',
                'name' => 'Spanish - Female (Neural)',
                'language' => 'es-ES',
                'gender' => 'female',
                'premium' => true,
            ],
            [
                'id' => 'fr-FR-Neural2-A',
                'name' => 'French - Female (Neural)',
                'language' => 'fr-FR',
                'gender' => 'female',
                'premium' => true,
            ],
            [
                'id' => 'de-DE-Neural2-A',
                'name' => 'German - Female (Neural)',
                'language' => 'de-DE',
                'gender' => 'female',
                'premium' => true,
            ],
        ];

        return response()->json(['data' => $voices]);
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
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Log::info('Retrieving IVR menus list', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

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

        Log::info('IVR menus list retrieved', [
            'request_id' => $requestId,
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
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validated();

        Log::info('Creating new IVR menu', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'ivr_menu_name' => $validated['name'],
        ]);

        try {
            $ivrMenu = DB::transaction(function () use ($user, $validated): IvrMenu {
                // Extract options data
                $optionsData = $validated['options'] ?? [];
                unset($validated['options']);

                // Assign to current user's organization
                $validated['organization_id'] = $user->organization_id;

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

            Log::info('IVR menu created successfully', [
                'request_id' => $requestId,
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
            Log::error('Failed to create IVR menu', [
                'request_id' => $requestId,
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
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

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

        Log::info('IVR menu details retrieved', [
            'request_id' => $requestId,
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
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

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

        Log::info('Updating IVR menu', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'ivr_menu_id' => $ivrMenu->id,
            'ivr_menu_name' => $validated['name'],
        ]);

        try {
            $ivrMenu = DB::transaction(function () use ($ivrMenu, $validated): IvrMenu {
                // Extract options data
                $optionsData = $validated['options'] ?? [];
                unset($validated['options']);

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

            Log::info('IVR menu updated successfully', [
                'request_id' => $requestId,
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
            Log::error('Failed to update IVR menu', [
                'request_id' => $requestId,
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
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

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

        // Check if IVR menu is referenced by other IVR menus
        $referencingMenus = DB::table('ivr_menu_options')
            ->join('ivr_menus', 'ivr_menu_options.ivr_menu_id', '=', 'ivr_menus.id')
            ->where('ivr_menu_options.destination_type', 'ivr_menu')
            ->where('ivr_menu_options.destination_id', $ivrMenu->id)
            ->where('ivr_menus.organization_id', $user->organization_id)
            ->select('ivr_menus.id', 'ivr_menus.name')
            ->distinct()
            ->get();

        // Check if IVR menu is used as failover in other menus
        $failoverMenus = IvrMenu::where('organization_id', $user->organization_id)
            ->where('failover_destination_type', 'ivr_menu')
            ->where('failover_destination_id', $ivrMenu->id)
            ->where('id', '!=', $ivrMenu->id)
            ->select('id', 'name')
            ->get();

        // Check if IVR menu is referenced by DID routing
        $referencingDids = DB::table('phone_numbers')
            ->where('destination_type', 'ivr_menu')
            ->where('destination_id', $ivrMenu->id)
            ->where('organization_id', $user->organization_id)
            ->select('id', 'phone_number')
            ->get();

        $hasReferences = $referencingMenus->isNotEmpty() || $failoverMenus->isNotEmpty() || $referencingDids->isNotEmpty();

        if ($hasReferences) {
            $references = [];

            if ($referencingMenus->isNotEmpty()) {
                $references['ivr_menus'] = $referencingMenus->map(fn($menu) => [
                    'id' => $menu->id,
                    'name' => $menu->name,
                ])->toArray();
            }

            if ($failoverMenus->isNotEmpty()) {
                $references['failover_menus'] = $failoverMenus->map(fn($menu) => [
                    'id' => $menu->id,
                    'name' => $menu->name,
                ])->toArray();
            }

            if ($referencingDids->isNotEmpty()) {
                $references['phone_numbers'] = $referencingDids->map(fn($did) => [
                    'id' => $did->id,
                    'phone_number' => $did->phone_number,
                ])->toArray();
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

            Log::info('IVR menu deleted successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'ivr_menu_id' => $ivrMenu->id,
                'ivr_menu_name' => $ivrMenuName,
            ]);

            return response()->json([
                'message' => 'IVR menu deleted successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete IVR menu', [
                'request_id' => $requestId,
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
}