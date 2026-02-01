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
}
