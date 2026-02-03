<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Resources\ExtensionResource;
use App\Models\Extension;
use App\Services\CloudonixClient\CloudonixSubscriberService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Extension CRUD API controller.
 *
 * Handles CRUD operations for extensions within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class ExtensionCrudController extends AbstractApiCrudController
{
    use ApiRequestHandler, AppliesFilters;

    /**
     * Constructor.
     */
    public function __construct(
        protected CloudonixSubscriberService $subscriberService
    ) {
    }

    /**
     * Get the filter configuration for the index method.
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
     * Get the model class name for this controller.
     */
    protected function getModelClass(): string
    {
        return Extension::class;
    }

    /**
     * Get the resource class name for transforming models.
     */
    protected function getResourceClass(): string
    {
        return ExtensionResource::class;
    }

    /**
     * Get the allowed filter fields for the index method.
     */
    protected function getAllowedFilters(): array
    {
        return ['type', 'status', 'user_id', 'search'];
    }

    /**
     * Get the allowed sort fields for the index method.
     */
    protected function getAllowedSortFields(): array
    {
        return ['extension_number', 'type', 'status', 'created_at', 'updated_at'];
    }

    /**
     * Get the default sort field for the index method.
     */
    protected function getDefaultSortField(): string
    {
        return 'extension_number';
    }

    /**
     * Apply custom filters to the query.
     */
    protected function applyCustomFilters($query, Request $request): void
    {
        // Filters are applied via AppliesFilters trait
    }

    /**
     * Build the index query with eager loading.
     */
    protected function buildIndexQuery($query, Request $request): void
    {
        $query->with(Extension::DEFAULT_USER_FIELDS);
    }

    /**
     * Get the view ability for the model.
     */
    protected function getViewAbility(): string
    {
        return 'view';
    }

    /**
     * Get the create ability for the model.
     */
    protected function getCreateAbility(): string
    {
        return 'create';
    }

    /**
     * Get the update ability for the model.
     */
    protected function getUpdateAbility(): string
    {
        return 'update';
    }

    /**
     * Get the delete ability for the model.
     */
    protected function getDeleteAbility(): string
    {
        return 'delete';
    }

    /**
     * Hook called before storing a new extension.
     *
     * Generates password for USER type extensions.
     */
    protected function beforeStore(array $validated, Request $request): array
    {
        $type = ExtensionType::from($validated['type']);

        // Generate password for USER type extensions
        if ($type === ExtensionType::USER) {
            $extension = new Extension();
            $validated['password'] = $extension->generateSecurePassword();

            \Log::info('Generated password for new USER extension', [
                'extension_number' => $validated['extension_number'],
                'organization_id' => $request->user()->organization_id,
            ]);
        } else {
            // Ensure password is null for non-USER extensions
            $validated['password'] = null;
        }

        return $validated;
    }

    /**
     * Hook called after storing a new extension.
     *
     * Syncs USER type extensions to Cloudonix.
     */
    protected function afterStore(Model $model, Request $request): void
    {
        /** @var Extension $extension */
        $extension = $model;

        // Load user relationship for API response
        $extension->load(Extension::DEFAULT_USER_FIELDS);

        // Sync to Cloudonix if USER type extension
        if ($extension->type === ExtensionType::USER) {
            $syncResult = $this->subscriberService->syncToCloudnonix($extension);

            if ($syncResult['success']) {
                \Log::info('Extension synced to Cloudonix', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'subscriber_id' => $extension->cloudonix_subscriber_id,
                ]);

                // Refresh to get updated Cloudonix fields
                $extension->refresh();
            } else {
                \Log::warning('Failed to sync extension to Cloudonix (non-blocking)', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'error' => $syncResult['error'] ?? 'Unknown error',
                    'details' => $syncResult['details'] ?? [],
                ]);
            }
        }
    }

    /**
     * Hook called before updating an extension.
     *
     * Handles password generation/clearing when type changes.
     */
    protected function beforeUpdate(Model $model, array $validated, Request $request): array
    {
        /** @var Extension $extension */
        $extension = $model;

        // Check if type is being changed
        if (isset($validated['type'])) {
            $newType = ExtensionType::from($validated['type']);
            $oldType = $extension->type;

            // Changing TO USER type
            if ($newType === ExtensionType::USER && $oldType !== ExtensionType::USER) {
                // Generate password if not already present
                if (empty($extension->password)) {
                    $validated['password'] = $extension->generateSecurePassword();

                    \Log::info('Generated password for extension type change to USER', [
                        'extension_id' => $extension->id,
                        'extension_number' => $extension->extension_number,
                        'old_type' => $oldType->value,
                        'new_type' => $newType->value,
                    ]);
                }
            }

            // Changing FROM USER type
            if ($oldType === ExtensionType::USER && $newType !== ExtensionType::USER) {
                // Clear password
                $validated['password'] = null;

                \Log::info('Cleared password for extension type change from USER', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'old_type' => $oldType->value,
                    'new_type' => $newType->value,
                ]);
            }
        }

        return $validated;
    }

    /**
     * Hook called after updating an extension.
     *
     * Handles Cloudonix sync/unsync based on type changes.
     */
    protected function afterUpdate(Model $model, Request $request): void
    {
        /** @var Extension $extension */
        $extension = $model;

        // Get the original type before update
        $originalType = $extension->getOriginal('type');
        $currentType = $extension->type;

        // Type changed TO USER - sync to Cloudonix
        if ($currentType === ExtensionType::USER && $originalType !== ExtensionType::USER->value) {
            $syncResult = $this->subscriberService->syncToCloudnonix($extension);

            if ($syncResult['success']) {
                \Log::info('Extension synced to Cloudonix after type change', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'old_type' => $originalType,
                    'new_type' => $currentType->value,
                ]);

                // Refresh to get updated Cloudonix fields
                $extension->refresh();
            } else {
                \Log::warning('Failed to sync extension to Cloudonix after type change', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'error' => $syncResult['error'] ?? 'Unknown error',
                ]);
            }
        }

        // Type changed FROM USER - unsync from Cloudonix
        if ($originalType === ExtensionType::USER->value && $currentType !== ExtensionType::USER) {
            $unsyncResult = $this->subscriberService->unsyncFromCloudonix($extension);

            if ($unsyncResult) {
                \Log::info('Extension unsynced from Cloudonix after type change', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                    'old_type' => $originalType,
                    'new_type' => $currentType->value,
                ]);
            } else {
                \Log::warning('Failed to unsync extension from Cloudonix after type change', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                ]);
            }
        }
    }

    /**
     * Hook called before deleting an extension.
     *
     * Unsyncs USER type extensions from Cloudonix.
     */
    protected function beforeDestroy(Model $model, Request $request): void
    {
        /** @var Extension $extension */
        $extension = $model;

        // Unsync from Cloudonix if USER type extension
        if ($extension->type === ExtensionType::USER) {
            $unsyncResult = $this->subscriberService->unsyncFromCloudonix($extension);

            if ($unsyncResult) {
                \Log::info('Extension unsynced from Cloudonix before deletion', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                ]);
            } else {
                \Log::warning('Failed to unsync extension from Cloudonix before deletion (continuing with deletion)', [
                    'extension_id' => $extension->id,
                    'extension_number' => $extension->extension_number,
                ]);
            }
        }
    }
}
