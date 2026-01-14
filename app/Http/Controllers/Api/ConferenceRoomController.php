<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Requests\ConferenceRoom\StoreConferenceRoomRequest;
use App\Http\Requests\ConferenceRoom\UpdateConferenceRoomRequest;
use App\Http\Resources\ConferenceRoomResource;
use App\Models\ConferenceRoom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conference Room management API controller.
 *
 * Handles CRUD operations for conference rooms within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class ConferenceRoomController extends AbstractApiCrudController
{
    /**
     * Get the model class name for this controller.
     */
    protected function getModelClass(): string
    {
        return ConferenceRoom::class;
    }

    /**
     * Get the resource class name for transforming models.
     */
    protected function getResourceClass(): string
    {
        return ConferenceRoomResource::class;
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
        return ['name', 'max_participants', 'status', 'created_at', 'updated_at'];
    }

    /**
     * Get the default sort field for the index method.
     */
    protected function getDefaultSortField(): string
    {
        return 'name';
    }

    /**
     * Apply custom filters to the query.
     */
    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        // Apply status filter
        if ($request->has('status')) {
            $status = UserStatus::tryFrom($request->input('status'));
            if ($status) {
                $query->withStatus($status);
            }
        }

        // Apply search filter
        if ($request->has('search') && $request->filled('search')) {
            $query->search($request->input('search'));
        }
    }

    /**
     * Get the policy ability name for create.
     *
     * Override if different from default.
     */
    protected function getCreateAbility(): string
    {
        return 'create';
    }

    /**
     * Get the policy ability name for update.
     *
     * Override if different from default.
     */
    protected function getUpdateAbility(): string
    {
        return 'update';
    }

    /**
     * Store a newly created conference room.
     * 
     * Laravel's DI will inject StoreConferenceRoomRequest and run validation automatically.
     */
    public function store(StoreConferenceRoomRequest $request): JsonResponse
    {
        // Cast to base Request type for parent compatibility
        return parent::store($request);
    }

    /**
     * Update the specified conference room.
     * 
     * Laravel's DI will inject UpdateConferenceRoomRequest and run validation automatically.
     */
    public function update(UpdateConferenceRoomRequest $request): JsonResponse
    {
        // Cast to base Request type for parent compatibility
        return parent::update($request);
    }
}
