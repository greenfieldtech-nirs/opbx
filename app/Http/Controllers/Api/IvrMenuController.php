<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\IvrMenuStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
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
        $query = IvrMenu::query()
            ->forOrganization($user->organization_id)
            ->with([
                'options' => function ($query) {
                    $query->select('id', 'ivr_menu_id', 'input_digits', 'description', 'destination_type', 'destination_id', 'priority')
                        ->orderBy('priority', 'asc');
                },
            ])
            ->withCount('options');

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
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'audio_file_path' => 'nullable|string|max:500',
            'tts_text' => 'nullable|string|max:1000',
            'max_turns' => 'required|integer|min:1|max:9',
            'failover_destination_type' => 'required|string|in:extension,ring_group,conference_room,ivr_menu,hangup',
            'failover_destination_id' => 'nullable|integer',
            'status' => 'required|string|in:active,inactive',
            'options' => 'required|array|min:1|max:20',
            'options.*.input_digits' => 'required|string|max:10',
            'options.*.description' => 'nullable|string|max:255',
            'options.*.destination_type' => 'required|string|in:extension,ring_group,conference_room,ivr_menu',
            'options.*.destination_id' => 'required|integer',
            'options.*.priority' => 'required|integer|min:1|max:20',
        ]);

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
     * @param Request $request
     * @param IvrMenu $ivrMenu
     * @return JsonResponse
     */
    public function update(Request $request, IvrMenu $ivrMenu): JsonResponse
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

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'audio_file_path' => 'nullable|string|max:500',
            'tts_text' => 'nullable|string|max:1000',
            'max_turns' => 'required|integer|min:1|max:9',
            'failover_destination_type' => 'required|string|in:extension,ring_group,conference_room,ivr_menu,hangup',
            'failover_destination_id' => 'nullable|integer',
            'status' => 'required|string|in:active,inactive',
            'options' => 'required|array|min:1|max:20',
            'options.*.input_digits' => 'required|string|max:10',
            'options.*.description' => 'nullable|string|max:255',
            'options.*.destination_type' => 'required|string|in:extension,ring_group,conference_room,ivr_menu',
            'options.*.destination_id' => 'required|integer',
            'options.*.priority' => 'required|integer|min:1|max:20',
        ]);

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

        // Check if IVR menu is referenced by other IVR menus or DID routing
        $isReferenced = DB::table('ivr_menu_options')
            ->where('destination_type', 'ivr_menu')
            ->where('destination_id', $ivrMenu->id)
            ->exists();

        if ($isReferenced) {
            return response()->json([
                'error' => 'Cannot delete IVR menu',
                'message' => 'This IVR menu is referenced by other menus and cannot be deleted.',
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