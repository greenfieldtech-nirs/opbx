<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Extension\StoreExtensionRequest;
use App\Http\Requests\Extension\UpdateExtensionRequest;
use App\Http\Resources\ExtensionResource;
use App\Models\Extension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Extension management API controller.
 *
 * Handles CRUD operations for extensions within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class ExtensionController extends Controller
{
    /**
     * Display a paginated list of extensions.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requestId = (string) Str::uuid();
        $user = $request->user();

        if (!$user) {
            abort(401, 'Unauthenticated');
        }

        Gate::authorize('viewAny', Extension::class);

        // Build query
        $query = Extension::query()
            ->forOrganization($user->organization_id)
            ->with('user:id,organization_id,name,email,role,status');

        // Apply filters
        if ($request->has('type')) {
            $type = ExtensionType::tryFrom($request->input('type'));
            if ($type) {
                $query->withType($type);
            }
        }

        if ($request->has('status')) {
            $status = UserStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->withStatus($status);
            }
        }

        if ($request->has('user_id') && $request->filled('user_id')) {
            $query->forUser($request->input('user_id'));
        }

        if ($request->has('search') && $request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Apply sorting
        $sortField = $request->input('sort_by', 'extension_number');
        $sortOrder = $request->input('sort_order', 'asc');

        // Validate sort field
        $allowedSortFields = ['extension_number', 'type', 'status', 'created_at', 'updated_at'];
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'extension_number';
        }

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : 'asc';

        $query->orderBy($sortField, $sortOrder);

        // Paginate
        $perPage = (int) $request->input('per_page', 20);
        $perPage = min(max($perPage, 1), 100); // Clamp between 1 and 100

        $extensions = $query->paginate($perPage);

        Log::info('Extensions list retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $extensions->total(),
            'per_page' => $perPage,
            'filters' => [
                'type' => $request->input('type'),
                'status' => $request->input('status'),
                'user_id' => $request->input('user_id'),
                'search' => $request->input('search'),
            ],
        ]);

        return ExtensionResource::collection($extensions);
    }

    /**
     * Store a newly created extension.
     *
     * @param StoreExtensionRequest $request
     * @return JsonResponse
     */
    public function store(StoreExtensionRequest $request): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $currentUser = $request->user();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validated();

        Log::info('Creating new extension', [
            'request_id' => $requestId,
            'creator_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'extension_number' => $validated['extension_number'],
            'type' => $validated['type'],
        ]);

        try {
            $extension = DB::transaction(function () use ($currentUser, $validated): Extension {
                // Assign to current user's organization
                $validated['organization_id'] = $currentUser->organization_id;

                // Create extension
                $extension = Extension::create($validated);

                return $extension;
            });

            // Load user relationship
            $extension->load('user:id,organization_id,name,email,role,status');

            Log::info('Extension created successfully', [
                'request_id' => $requestId,
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            return response()->json([
                'message' => 'Extension created successfully.',
                'extension' => new ExtensionResource($extension),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create extension', [
                'request_id' => $requestId,
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to create extension',
                'message' => 'An error occurred while creating the extension.',
            ], 500);
        }
    }

    /**
     * Display the specified extension.
     *
     * @param Request $request
     * @param Extension $extension
     * @return JsonResponse
     */
    public function show(Request $request, Extension $extension): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $currentUser = $request->user();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Gate::authorize('view', $extension);

        // Tenant scope check
        if ($extension->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant extension access attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_extension_id' => $extension->id,
                'target_organization_id' => $extension->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Extension not found.',
            ], 404);
        }

        // Load user relationship
        $extension->load('user:id,organization_id,name,email,role,status');

        Log::info('Extension details retrieved', [
            'request_id' => $requestId,
            'user_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'extension_id' => $extension->id,
        ]);

        return response()->json([
            'extension' => new ExtensionResource($extension),
        ]);
    }

    /**
     * Update the specified extension.
     *
     * @param UpdateExtensionRequest $request
     * @param Extension $extension
     * @return JsonResponse
     */
    public function update(UpdateExtensionRequest $request, Extension $extension): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $currentUser = $request->user();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Tenant scope check
        if ($extension->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant extension update attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_extension_id' => $extension->id,
                'target_organization_id' => $extension->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Extension not found.',
            ], 404);
        }

        $validated = $request->validated();

        // Track changed fields for logging
        $changedFields = [];
        foreach ($validated as $key => $value) {
            if ($key === 'configuration') {
                if (json_encode($extension->{$key}) !== json_encode($value)) {
                    $changedFields[] = $key;
                }
            } elseif ($extension->{$key} != $value) {
                $changedFields[] = $key;
            }
        }

        Log::info('Updating extension', [
            'request_id' => $requestId,
            'updater_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'extension_id' => $extension->id,
            'changed_fields' => $changedFields,
        ]);

        try {
            DB::transaction(function () use ($extension, $validated): void {
                // Update extension
                $extension->update($validated);
            });

            // Reload extension and user relationship
            $extension->refresh();
            $extension->load('user:id,organization_id,name,email,role,status');

            Log::info('Extension updated successfully', [
                'request_id' => $requestId,
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
                'changed_fields' => $changedFields,
            ]);

            return response()->json([
                'message' => 'Extension updated successfully.',
                'extension' => new ExtensionResource($extension),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update extension', [
                'request_id' => $requestId,
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to update extension',
                'message' => 'An error occurred while updating the extension.',
            ], 500);
        }
    }

    /**
     * Remove the specified extension.
     *
     * @param Request $request
     * @param Extension $extension
     * @return JsonResponse
     */
    public function destroy(Request $request, Extension $extension): JsonResponse
    {
        $requestId = (string) Str::uuid();
        $currentUser = $request->user();

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Gate::authorize('delete', $extension);

        // Tenant scope check
        if ($extension->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant extension deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'target_extension_id' => $extension->id,
                'target_organization_id' => $extension->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Extension not found.',
            ], 404);
        }

        Log::info('Deleting extension', [
            'request_id' => $requestId,
            'deleter_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
        ]);

        try {
            DB::transaction(function () use ($extension): void {
                // Hard delete the extension
                $extension->delete();
            });

            Log::info('Extension deleted successfully', [
                'request_id' => $requestId,
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
            ]);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete extension', [
                'request_id' => $requestId,
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to delete extension',
                'message' => 'An error occurred while deleting the extension.',
            ], 500);
        }
    }
}
