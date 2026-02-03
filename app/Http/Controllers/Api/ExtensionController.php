<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Controllers\Traits\LogsOperations;
use App\Http\Requests\Extension\StoreExtensionRequest;
use App\Http\Requests\Extension\UpdateExtensionRequest;
use App\Http\Resources\ExtensionResource;
use App\Models\Extension;
use App\Services\CloudonixClient\CloudonixSubscriberService;
use App\Services\Logging\AuditLogger;
use App\Services\PasswordGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Extension management API controller.
 *
 * Handles CRUD operations for extensions within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class ExtensionController extends Controller
{
    use ApiRequestHandler, AppliesFilters, LogsOperations;

    /**
     * Get the filter configuration for the index method.
     *
     * @return array<string, array>
     */
    protected function getFilterConfig(): array
    {
        return [
            'type' => [
                'type' => 'enum',
                'enum' => ExtensionType::class,
                'scope' => 'withType',
            ],
            'status' => [
                'type' => 'enum',
                'enum' => UserStatus::class,
                'scope' => 'withStatus',
            ],
            'user_id' => [
                'type' => 'column',
                'scope' => 'forUser',
                'require_filled' => true,
            ],
            'search' => [
                'type' => 'search',
                'scope' => 'search',
            ],
        ];
    }

    /**
     * Display a paginated list of extensions.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        $this->authorize('viewAny', Extension::class);

        // Build query
        $query = Extension::query()
            ->forOrganization($user->organization_id)
            ->with(Extension::DEFAULT_USER_FIELDS);

        // Apply filters
        $query = $this->applyFilters($query, $request, $this->getFilterConfig());

        // Apply sorting
        $sortField = $request->input('sort_by', 'extension_number');
        $sortOrder = $request->input('sort_order', 'asc');

        // Validate sort field
        $allowedSortFields = ['extension_number', 'type', 'status', 'created_at', 'updated_at'];
        if (! in_array($sortField, $allowedSortFields, true)) {
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

        $this->logListRetrieved('Extension', [
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
     */
    public function store(
        StoreExtensionRequest $request,
        PasswordGenerator $passwordGenerator,
        CloudonixSubscriberService $subscriberService
    ): JsonResponse {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        $validated = $request->validated();

        // DEBUG: Log validated data
        Log::info('[ExtensionController] Validated data received', [
            'request_id' => $requestId,
            'validated_type' => $validated['type'] ?? 'NOT SET',
            'validated_keys' => array_keys($validated),
            'raw_type_value' => is_object($validated['type'] ?? null) ? get_class($validated['type']) : gettype($validated['type'] ?? null),
        ]);

        // Assign to current user's organization
        $validated['organization_id'] = $currentUser->organization_id;

        // Only USER type extensions need a password for SIP authentication
        // Other types (Conference, IVR, AI Assistant, Ring Group, Forward) don't require passwords
        $extensionType = $validated['type'] ?? null;

        // Handle both string and enum object cases
        // Laravel's Enum validation can return either the string value or the enum object
        if (is_object($extensionType) && $extensionType instanceof ExtensionType) {
            $isUserExtension = $extensionType === ExtensionType::USER;
            $extensionTypeValue = $extensionType->value;
        } elseif (is_string($extensionType)) {
            $isUserExtension = $extensionType === ExtensionType::USER->value;
            $extensionTypeValue = $extensionType;
        } else {
            $isUserExtension = false;
            $extensionTypeValue = 'UNKNOWN';
        }

        Log::info('[ExtensionController] Type comparison check', [
            'request_id' => $requestId,
            'extension_type_raw' => $validated['type'] ?? null,
            'extension_type_class' => is_object($validated['type'] ?? null) ? get_class($validated['type']) : 'NOT_OBJECT',
            'extension_type_value' => $extensionTypeValue,
            'user_type_value' => ExtensionType::USER->value,
            'is_user_extension' => $isUserExtension,
        ]);

        if ($isUserExtension) {
            // Auto-generate strong passphrase for USER extensions (3 words)
            $generatedPassword = '';

            if (! empty($passwordGenerator)) {
                $generatedPassword = $passwordGenerator->generate();
            }

            // Fallback: generate a secure random password if service injection failed
            if (empty($generatedPassword)) {
                Log::warning('[ExtensionController] Using fallback password generation', [
                    'request_id' => $requestId,
                    'creator_id' => $currentUser->id,
                ]);
                $generatedPassword = bin2hex(random_bytes(16)); // 32 character hex string
            }

            if (empty($generatedPassword)) {
                Log::error('[ExtensionController] Failed to generate password', [
                    'request_id' => $requestId,
                    'creator_id' => $currentUser->id,
                ]);

                return response()->json([
                    'error' => 'Password generation failed',
                    'message' => 'Could not generate a secure password for the extension.',
                ], 500);
            }

            $validated['password'] = $generatedPassword;

            Log::info('[ExtensionController] Password generated for USER extension', [
                'request_id' => $requestId,
                'extension_number' => $validated['extension_number'] ?? 'UNKNOWN',
                'password_length' => strlen($generatedPassword),
            ]);
        } else {
            // Non-USER extensions don't need passwords
            Log::info('[ExtensionController] Skipping password generation for non-USER extension', [
                'request_id' => $requestId,
                'extension_number' => $validated['extension_number'] ?? 'UNKNOWN',
                'extension_type' => $extensionType,
            ]);
        }

        try {
            $extension = DB::transaction(function () use ($validated): Extension {
                // DEBUG: Log what's being passed to create
                Log::info('[ExtensionController] Creating extension with data', [
                    'extension_number' => $validated['extension_number'] ?? 'MISSING',
                    'has_password' => isset($validated['password']),
                    'password_value' => isset($validated['password']) ? 'set ('.strlen($validated['password']).' chars)' : 'NOT SET',
                ]);

                // Create extension
                $extension = Extension::create($validated);

                return $extension;
            });

            // Load user relationship
            $extension->loadMissing(Extension::DEFAULT_USER_FIELDS);

            $this->logOperationCompleted('Extension', 'creation', [
                'creator_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            // Add audit logging for extension creation
            try {
                AuditLogger::logExtensionCreated($request, $extension);
            } catch (\Exception $auditException) {
                // Log audit failure but don't fail the operation
                Log::error('Failed to log extension creation audit', [
                    'extension_id' => $extension->id,
                    'error' => $auditException->getMessage(),
                ]);
            }

            // Sync to Cloudonix if USER type extension
            $cloudonixWarning = $this->syncExtensionToCloudonix($extension, $subscriberService);

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
            $this->logOperationFailed('Extension', 'creation', [
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
     */
    public function show(Request $request, Extension $extension): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        $this->authorize('view', $extension);

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
        $extension->loadMissing(Extension::DEFAULT_USER_FIELDS);

        $this->logDetailsRetrieved('Extension', [
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
     */
    public function update(
        UpdateExtensionRequest $request,
        Extension $extension,
        CloudonixSubscriberService $subscriberService,
        PasswordGenerator $passwordGenerator
    ): JsonResponse {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

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

        // Check if extension type is changing to USER
        $oldType = $extension->type->value;
        $newType = $validated['type'] ?? $oldType;
        $isChangingToUser = ($oldType !== ExtensionType::USER->value && $newType === ExtensionType::USER->value);

        // If changing to USER type, ensure password is set
        if ($isChangingToUser) {
            // Check if password needs to be generated
            if (empty($extension->password)) {
                $generatedPassword = '';

                if (! empty($passwordGenerator)) {
                    $generatedPassword = $passwordGenerator->generate();
                }

                // Fallback if service injection failed
                if (empty($generatedPassword)) {
                    $generatedPassword = bin2hex(random_bytes(16));
                }

                $validated['password'] = $generatedPassword;

                Log::info('[ExtensionController] Auto-generated password for extension type change to USER', [
                    'request_id' => $requestId,
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'old_type' => $oldType,
                    'new_type' => $newType,
                ]);
            }
        }

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

        // Log will be handled by success/failure methods below

        try {
            DB::transaction(function () use ($extension, $validated): void {
                // Update extension
                $extension->update($validated);
            });

            // Reload extension and user relationship
            $extension->refresh();
            $extension->loadMissing(Extension::DEFAULT_USER_FIELDS);

            $this->logOperationCompleted('Extension', 'update', [
                'updater_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
                'changed_fields' => $changedFields,
            ]);

            // Add audit logging for extension update
            try {
                AuditLogger::logExtensionUpdated($request, $extension, $changedFields);
            } catch (\Exception $auditException) {
                // Log audit failure but don't fail the operation
                Log::error('Failed to log extension update audit', [
                    'extension_id' => $extension->id,
                    'error' => $auditException->getMessage(),
                ]);
            }

            // Determine sync requirements
            // If changing TO USER, don't require previously synced (force new sync)
            // Otherwise, only sync if already synced
            $requireSynced = ! $isChangingToUser;

            // Sync to Cloudonix if USER type extension
            $cloudonixWarning = $this->syncExtensionToCloudonix(
                $extension,
                $subscriberService,
                true,  // isUpdate
                'updated',
                $requireSynced
            );

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
            $this->logOperationFailed('Extension', 'update', [
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
     */
    public function destroy(
        Request $request,
        Extension $extension,
        CloudonixSubscriberService $subscriberService
    ): JsonResponse {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        $this->authorize('delete', $extension);

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

        // Log will be handled by success/failure methods below

        // Check for references before deletion
        $referenceChecker = app(\App\Services\ResourceReferenceChecker::class);
        $result = $referenceChecker->checkReferences('extension', $extension->id, $extension->organization_id);

        if ($result['has_references']) {
            return response()->json([
                'error' => 'Cannot delete extension',
                'message' => 'This extension is being used and cannot be deleted. Please remove all references first.',
                'references' => $result['references'],
            ], 409);
        }

        // Add audit logging for extension deletion (before actual deletion)
        try {
            AuditLogger::logExtensionDeleted($request, $extension->id, $extension->extension_number);
        } catch (\Exception $auditException) {
            // Log audit failure but don't fail the operation
            Log::error('Failed to log extension deletion audit', [
                'extension_id' => $extension->id,
                'error' => $auditException->getMessage(),
            ]);
        }

        try {
            // Unsync from Cloudonix before deletion if synced
            if ($extension->type === ExtensionType::USER && $extension->cloudonix_synced) {
                $unsyncSuccess = $subscriberService->unsyncFromCloudonix($extension);

                if ($unsyncSuccess) {
                    $this->logOperationCompleted('Extension', 'unsync', [
                        'extension_id' => $extension->id,
                    ]);
                } else {
                    $this->logOperationFailed('Extension', 'unsync', [
                        'extension_id' => $extension->id,
                    ], true);
                }
            }

            DB::transaction(function () use ($extension): void {
                // Hard delete the extension
                $extension->delete();
            });

            $this->logOperationCompleted('Extension', 'deletion', [
                'deleter_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
            ]);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            $this->logOperationFailed('Extension', 'deletion', [
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
     */
    public function compareSync(Request $request, CloudonixSubscriberService $subscriberService): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        $this->authorize('viewAny', Extension::class);

        // Log will be handled by success/failure methods below

        try {
            $organization = $currentUser->organization()->with('cloudonixSettings')->first();

            if (! $organization) {
                return response()->json([
                    'error' => 'Organization not found',
                ], 404);
            }

            $comparison = $subscriberService->compareWithCloudonix($organization);

            $this->logOperationCompleted('Extension', 'comparison', [
                'comparison' => $comparison,
            ]);

            return response()->json($comparison);
        } catch (\Exception $e) {
            $this->logOperationFailed('Extension', 'comparison', [
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
     */
    public function resetPassword(
        Request $request,
        Extension $extension,
        PasswordGenerator $passwordGenerator,
        CloudonixSubscriberService $subscriberService
    ): JsonResponse {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        // Only Owner and PBX Admin can reset extension passwords
        if (! $currentUser->isOwner() && ! $currentUser->isPBXAdmin()) {
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

        // Log will be handled by success/failure methods below

        try {
            // Generate new memorable password using the same method as extension creation
            $newPassword = $passwordGenerator->generate();

            // Update the extension with the new password
            $extension->password = $newPassword;
            $extension->save();

            // Reload extension to get updated data
            $extension->refresh();
            $extension->loadMissing(Extension::DEFAULT_USER_FIELDS);

            $this->logOperationCompleted('Extension', 'password reset', [
                'user_id' => $currentUser->id,
                'organization_id' => $currentUser->organization_id,
                'extension_id' => $extension->id,
                'extension_number' => $extension->extension_number,
            ]);

            // Add audit logging for password reset
            try {
                AuditLogger::log('extension.password_reset', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'user_id' => $extension->user_id,
                    'organization_id' => $currentUser->organization_id,
                ], AuditLogger::LEVEL_INFO, $request, $currentUser);
            } catch (\Exception $auditException) {
                // Log audit failure but don't fail the operation
                Log::error('Failed to log extension password reset audit', [
                    'extension_id' => $extension->id,
                    'error' => $auditException->getMessage(),
                ]);
            }

            // Sync to Cloudonix if USER type extension and already synced
            $cloudonixWarning = $this->syncExtensionToCloudonix(
                $extension,
                $subscriberService,
                true,
                'password_reset',
                true
            );

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
            $this->logOperationFailed('Extension', 'password reset', [
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
     */
    public function getPassword(Request $request, Extension $extension): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        // Only Owner and PBX Admin can view extension passwords
        if (! $currentUser->isOwner() && ! $currentUser->isPBXAdmin()) {
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

        $this->logDetailsRetrieved('Extension password', [
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
     * Sync extension to Cloudonix with proper error handling and logging.
     *
     * @param  string  $syncContext  Context for warning message ('created', 'updated', 'password_reset')
     * @param  bool  $requireSynced  Whether extension must already be synced (default false)
     * @return array|null Warning array if sync failed, null on success
     */
    protected function syncExtensionToCloudonix(
        Extension $extension,
        CloudonixSubscriberService $service,
        bool $isUpdate = false,
        string $syncContext = 'created',
        bool $requireSynced = false
    ): ?array {
        $requestId = $this->getRequestId();

        // Only sync USER type extensions
        if ($extension->type !== ExtensionType::USER) {
            return null;
        }

        // For updates and password resets, only sync if already synced
        if ($requireSynced && ! $extension->cloudonix_synced) {
            return null;
        }

        $syncResult = $service->syncToCloudnonix($extension, $isUpdate);

        if ($syncResult['success']) {
            $this->logOperationCompleted('Extension', 'sync', [
                'extension_id' => $extension->id,
                'subscriber_id' => $extension->cloudonix_subscriber_id,
            ]);
        } else {
            $this->logOperationFailed('Extension', 'sync', [
                'extension_id' => $extension->id,
                'error' => $syncResult['error'] ?? 'Unknown error',
                'details' => $syncResult['details'] ?? [],
            ], true);

            // Prepare warning message for API response based on context
            $messageMap = [
                'created' => 'Extension created locally but Cloudonix sync failed',
                'updated' => 'Extension updated locally but Cloudonix sync failed',
                'password_reset' => 'Extension password reset locally but Cloudonix sync failed',
            ];

            $cloudonixWarning = [
                'message' => $messageMap[$syncContext] ?? $messageMap['created'],
                'error' => $syncResult['error'] ?? 'Unknown error',
                'details' => $syncResult['details'] ?? [],
            ];
        }

        // Refresh to get updated Cloudonix fields
        $extension->refresh();

        return $cloudonixWarning ?? null;
    }

    /**
     * Perform bi-directional sync between local extensions and Cloudonix.
     */
    public function performSync(Request $request, CloudonixSubscriberService $subscriberService): JsonResponse
    {
        $requestId = $this->getRequestId();
        $currentUser = $this->getAuthenticatedUser();

        // Only Owner and PBX Admin can sync extensions
        if (! $currentUser->isOwner() && ! $currentUser->isPBXAdmin()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Only Owner and PBX Admin can sync extensions.',
            ], 403);
        }

        // Log will be handled by success/failure methods below

        try {
            $organization = $currentUser->organization()->with('cloudonixSettings')->first();

            if (! $organization) {
                return response()->json([
                    'error' => 'Organization not found',
                ], 404);
            }

            $result = $subscriberService->bidirectionalSync($organization);

            if (! $result['success']) {
                $this->logOperationFailed('Extension', 'bi-directional sync', [
                    'error' => $result['error'] ?? 'Unknown error',
                ], true);

                return response()->json([
                    'success' => false,
                    'error' => $result['error'] ?? 'Sync failed',
                    'result' => $result,
                ], 400);
            }

            $this->logOperationCompleted('Extension', 'bi-directional sync', [
                'result' => $result,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Extensions synchronized successfully.',
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            $this->logOperationFailed('Extension', 'bi-directional sync', [
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
