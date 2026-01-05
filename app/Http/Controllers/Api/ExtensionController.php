<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\Extension\StoreExtensionRequest;
use App\Http\Requests\Extension\UpdateExtensionRequest;
use App\Http\Resources\ExtensionResource;
use App\Models\Extension;
use App\Services\CloudonixClient\CloudonixSubscriberService;
use App\Services\PasswordGenerator;
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
    use ApiRequestHandler;
{
    /**
     * Display a paginated list of extensions.
     *
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

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
     * @param PasswordGenerator $passwordGenerator
     * @param CloudonixSubscriberService $subscriberService
     * @return JsonResponse
     */
    public function store(
        StoreExtensionRequest $request,
        PasswordGenerator $passwordGenerator,
        CloudonixSubscriberService $subscriberService
    ): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

        $validated = $request->validated();

        Log::info('Creating new extension', [
            'request_id' => $requestId,
            'creator_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'extension_number' => $validated['extension_number'],
            'type' => $validated['type'],
        ]);

        // Assign to current user's organization
        $validated['organization_id'] = $currentUser->organization_id;

        // Auto-generate strong passphrase for the extension (3 words)
        $validated['password'] = $passwordGenerator->generate();

        try {
            $extension = DB::transaction(function () use ($validated): Extension {
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

            // Sync to Cloudonix if USER type extension
            $cloudonixWarning = null;
            if ($extension->type === ExtensionType::USER) {
                $syncResult = $subscriberService->syncToCloudnonix($extension);

                if ($syncResult['success']) {
                    Log::info('Extension synced to Cloudonix', [
                        'request_id' => $requestId,
                        'extension_id' => $extension->id,
                        'subscriber_id' => $extension->cloudonix_subscriber_id,
                    ]);
                } else {
                    Log::warning('Failed to sync extension to Cloudonix (non-blocking)', [
                        'request_id' => $requestId,
                        'extension_id' => $extension->id,
                        'error' => $syncResult['error'] ?? 'Unknown error',
                        'details' => $syncResult['details'] ?? [],
                    ]);

                    // Prepare warning message for API response
                    $cloudonixWarning = [
                        'message' => 'Extension created locally but Cloudonix sync failed',
                        'error' => $syncResult['error'] ?? 'Unknown error',
                        'details' => $syncResult['details'] ?? [],
                    ];
                }

                // Refresh to get updated Cloudonix fields
                $extension->refresh();
            }

            $response = [
                'message' => 'Extension created successfully.',
                'extension' => new ExtensionResource($extension),
            ];

            // Include Cloudonix warning if sync failed
            if ($cloudonixWarning) {
                $response['cloudonix_warning'] = $cloudonixWarning;
            }

            return response()->json($response, 201);
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
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

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
     * @param CloudonixSubscriberService $subscriberService
     * @return JsonResponse
     */
    public function update(
        UpdateExtensionRequest $request,
        Extension $extension,
        CloudonixSubscriberService $subscriberService
    ): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

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

            // Sync to Cloudonix if USER type extension and already synced
            $cloudonixWarning = null;
            if ($extension->type === ExtensionType::USER && $extension->cloudonix_synced) {
                $syncResult = $subscriberService->syncToCloudnonix($extension, true);

                if ($syncResult['success']) {
                    Log::info('Extension changes synced to Cloudonix', [
                        'request_id' => $requestId,
                        'extension_id' => $extension->id,
                        'subscriber_id' => $extension->cloudonix_subscriber_id,
                    ]);
                } else {
                    Log::warning('Failed to sync extension changes to Cloudonix (non-blocking)', [
                        'request_id' => $requestId,
                        'extension_id' => $extension->id,
                        'error' => $syncResult['error'] ?? 'Unknown error',
                        'details' => $syncResult['details'] ?? [],
                    ]);

                    // Prepare warning message for API response
                    $cloudonixWarning = [
                        'message' => 'Extension updated locally but Cloudonix sync failed',
                        'error' => $syncResult['error'] ?? 'Unknown error',
                        'details' => $syncResult['details'] ?? [],
                    ];
                }

                // Refresh to get any updated fields
                $extension->refresh();
            }

            $response = [
                'message' => 'Extension updated successfully.',
                'extension' => new ExtensionResource($extension),
            ];

            // Include Cloudonix warning if sync failed
            if ($cloudonixWarning) {
                $response['cloudonix_warning'] = $cloudonixWarning;
            }

            return response()->json($response);
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
     * @param CloudonixSubscriberService $subscriberService
     * @return JsonResponse
     */
    public function destroy(
        Request $request,
        Extension $extension,
        CloudonixSubscriberService $subscriberService
    ): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

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
            // Unsync from Cloudonix before deletion if synced
            if ($extension->type === ExtensionType::USER && $extension->cloudonix_synced) {
                $unsyncSuccess = $subscriberService->unsyncFromCloudonix($extension);

                if ($unsyncSuccess) {
                    Log::info('Extension unsynced from Cloudonix before deletion', [
                        'request_id' => $requestId,
                        'extension_id' => $extension->id,
                    ]);
                } else {
                    Log::warning('Failed to unsync extension from Cloudonix (non-blocking)', [
                        'request_id' => $requestId,
                        'extension_id' => $extension->id,
                    ]);
                }
            }

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

    /**
     * Compare local extensions with Cloudonix subscribers.
     *
     * @param Request $request
     * @param CloudonixSubscriberService $subscriberService
     * @return JsonResponse
     */
    public function compareSync(Request $request, CloudonixSubscriberService $subscriberService): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        Gate::authorize('viewAny', Extension::class);

        Log::info('Comparing extensions with Cloudonix', [
            'request_id' => $requestId,
            'user_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
        ]);

        try {
            $organization = $currentUser->organization()->with('cloudonixSettings')->first();

            if (!$organization) {
                return response()->json([
                    'error' => 'Organization not found',
                ], 404);
            }

            $comparison = $subscriberService->compareWithCloudonix($organization);

            Log::info('Extension comparison completed', [
                'request_id' => $requestId,
                'comparison' => $comparison,
            ]);

            return response()->json($comparison);
        } catch (\Exception $e) {
            Log::error('Failed to compare extensions', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to compare extensions',
                'message' => 'An error occurred while comparing extensions.',
            ], 500);
        }
    }

    /**
     * Reset the password for the specified extension.
     *
     * @param Request $request
     * @param Extension $extension
     * @param PasswordGenerator $passwordGenerator
     * @param CloudonixSubscriberService $subscriberService
     * @return JsonResponse
     */
    public function resetPassword(
        Request $request,
        Extension $extension,
        PasswordGenerator $passwordGenerator,
        CloudonixSubscriberService $subscriberService
    ): JsonResponse {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Only Owner and PBX Admin can reset extension passwords
        if (!$currentUser->isOwner() && !$currentUser->isPBXAdmin()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Only Owner and PBX Admin can reset extension passwords.',
            ], 403);
        }

        // Tenant scope check
        if ($extension->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant extension password reset attempt', [
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

        Log::info('Resetting extension password', [
            'request_id' => $requestId,
            'user_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
        ]);

        try {
            // Generate new memorable password using the same method as extension creation
            $newPassword = $passwordGenerator->generate();

            // Update the extension with the new password
            $extension->password = $newPassword;
            $extension->save();

            // Reload extension to get updated data
            $extension->refresh();
            $extension->load('user:id,organization_id,name,email,role,status');

            Log::info('Extension password reset successfully', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            // Sync to Cloudonix if USER type extension and already synced
            $cloudonixWarning = null;
            if ($extension->type === ExtensionType::USER && $extension->cloudonix_synced) {
                $syncResult = $subscriberService->syncToCloudnonix($extension, true);

                if ($syncResult['success']) {
                    Log::info('Extension password synced to Cloudonix', [
                        'request_id' => $requestId,
                        'extension_id' => $extension->id,
                        'subscriber_id' => $extension->cloudonix_subscriber_id,
                    ]);
                } else {
                    Log::warning('Failed to sync extension password to Cloudonix (non-blocking)', [
                        'request_id' => $requestId,
                        'extension_id' => $extension->id,
                        'error' => $syncResult['error'] ?? 'Unknown error',
                        'details' => $syncResult['details'] ?? [],
                    ]);

                    // Prepare warning message for API response
                    $cloudonixWarning = [
                        'message' => 'Extension password reset locally but Cloudonix sync failed',
                        'error' => $syncResult['error'] ?? 'Unknown error',
                        'details' => $syncResult['details'] ?? [],
                    ];
                }

                // Refresh to get any updated fields
                $extension->refresh();
            }

            $response = [
                'message' => 'Extension password reset successfully.',
                'new_password' => $newPassword,
                'extension' => new ExtensionResource($extension),
            ];

            // Include Cloudonix warning if sync failed
            if ($cloudonixWarning) {
                $response['cloudonix_warning'] = $cloudonixWarning;
            }

            return response()->json($response);
        } catch (\Exception $e) {
            Log::error('Failed to reset extension password', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to reset extension password',
                'message' => 'An error occurred while resetting the extension password.',
            ], 500);
        }
    }

    /**
     * Get the password for the specified extension.
     *
     * @param Request $request
     * @param Extension $extension
     * @return JsonResponse
     */
    public function getPassword(Request $request, Extension $extension): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Only Owner and PBX Admin can view extension passwords
        if (!$currentUser->isOwner() && !$currentUser->isPBXAdmin()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Only Owner and PBX Admin can view extension passwords.',
            ], 403);
        }

        // Tenant scope check
        if ($extension->organization_id !== $currentUser->organization_id) {
            Log::warning('Cross-tenant extension password access attempt', [
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

        // Only USER type extensions have passwords
        if ($extension->type !== \App\Enums\ExtensionType::USER) {
            return response()->json([
                'error' => 'Not Applicable',
                'message' => 'Only PBX User extensions have passwords.',
            ], 400);
        }

        Log::info('Extension password accessed', [
            'request_id' => $requestId,
            'user_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
        ]);

        return response()->json([
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
        ]);
    }

    /**
     * Perform bi-directional sync between local extensions and Cloudonix.
     *
     * @param Request $request
     * @param CloudonixSubscriberService $subscriberService
     * @return JsonResponse
     */
    public function performSync(Request $request, CloudonixSubscriberService $subscriberService): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser($request);

        if (!$currentUser) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Only Owner and PBX Admin can sync extensions
        if (!$currentUser->isOwner() && !$currentUser->isPBXAdmin()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Only Owner and PBX Admin can sync extensions.',
            ], 403);
        }

        Log::info('Starting bi-directional extension sync', [
            'request_id' => $requestId,
            'user_id' => $currentUser->id,
            'organization_id' => $currentUser->organization_id,
        ]);

        try {
            $organization = $currentUser->organization()->with('cloudonixSettings')->first();

            if (!$organization) {
                return response()->json([
                    'error' => 'Organization not found',
                ], 404);
            }

            $result = $subscriberService->bidirectionalSync($organization);

            if (!$result['success']) {
                Log::warning('Bi-directional sync failed', [
                    'request_id' => $requestId,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);

                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Sync failed',
                    'result' => $result,
                ], 400);
            }

            Log::info('Bi-directional sync completed successfully', [
                'request_id' => $requestId,
                'result' => $result,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Extensions synchronized successfully.',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to perform bi-directional sync', [
                'request_id' => $requestId,
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to sync extensions',
                'message' => 'An error occurred while syncing extensions.',
            ], 500);
        }
    }
}
