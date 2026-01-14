<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\OutboundWhitelist\StoreOutboundWhitelistRequest;
use App\Http\Requests\OutboundWhitelist\UpdateOutboundWhitelistRequest;
use App\Http\Resources\OutboundWhitelistResource;
use App\Models\OutboundWhitelist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Outbound Whitelist management API controller.
 *
 * Handles CRUD operations for outbound whitelist entries within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class OutboundWhitelistController extends AbstractApiCrudController
{

    /**
     * Get the model class name for this controller.
     */
    protected function getModelClass(): string
    {
        return OutboundWhitelist::class;
    }

    /**
     * Get the resource class name for transforming models.
     */
    protected function getResourceClass(): string
    {
        return OutboundWhitelistResource::class;
    }

    /**
     * Get the allowed filter fields for the index method.
     *
     * @return array<string>
     */
    protected function getAllowedFilters(): array
    {
        return ['search'];
    }

    /**
     * Get the allowed sort fields for the index method.
     *
     * @return array<string>
     */
    protected function getAllowedSortFields(): array
    {
        return ['destination_country', 'destination_prefix', 'outbound_trunk_name', 'created_at', 'updated_at'];
    }

    /**
     * Get the default sort field for the index method.
     */
    protected function getDefaultSortField(): string
    {
        return 'created_at';
    }

    /**
     * Apply custom filters to the query.
     */
    protected function applyCustomFilters(Builder $query, Request $request): void
    {
        // Apply search filter if provided
        if ($request->has('search') && $request->filled('search')) {
            $query->search($request->input('search'));
        }
    }

    /**
     * Display a paginated list of outbound whitelist entries.
     *
     * Overrides parent to maintain custom response format with meta pagination info.
     */
    public function index(Request $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $this->authorize($this->getViewAnyAbility(), $this->getModelClass());

        // Build query
        $modelClass = $this->getModelClass();
        $query = $modelClass::query()->forOrganization($user->organization_id);

        // Apply custom filters
        $this->applyCustomFilters($query, $request);

        // Apply sorting
        $sortField = $request->input('sort_by', $this->getDefaultSortField());
        $sortOrder = $request->input('sort_order', 'desc'); // Default to desc for backward compatibility

        // Validate sort field
        $allowedSortFields = $this->getAllowedSortFields();
        if (!in_array($sortField, $allowedSortFields, true)) {
            $sortField = $this->getDefaultSortField();
        }

        // Validate sort order
        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : 'desc';

        $query->orderBy($sortField, $sortOrder);

        // Paginate
        $perPage = (int) $request->input('per_page', 25); // Default to 25 for backward compatibility
        $perPage = min(max($perPage, 1), 100); // Clamp between 1 and 100

        $models = $query->paginate($perPage);

        // Build filters array for logging
        $filters = [];
        foreach ($this->getAllowedFilters() as $filter) {
            if ($request->has($filter)) {
                $filters[$filter] = $request->input($filter);
            }
        }

        \Illuminate\Support\Facades\Log::info($this->getPluralResourceKey() . ' list retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $models->total(),
            'per_page' => $perPage,
            'filters' => $filters,
        ]);

        $resourceClass = $this->getResourceClass();
        return response()->json([
            'data' => $resourceClass::collection($models->items()),
            'meta' => [
                'current_page' => $models->currentPage(),
                'per_page' => $models->perPage(),
                'total' => $models->total(),
                'last_page' => $models->lastPage(),
                'from' => $models->firstItem(),
                'to' => $models->lastItem(),
            ],
        ]);
    }
}