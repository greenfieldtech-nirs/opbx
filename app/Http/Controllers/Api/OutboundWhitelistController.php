<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiRequestHandler;
use App\Http\Requests\OutboundWhitelist\StoreOutboundWhitelistRequest;
use App\Http\Requests\OutboundWhitelist\UpdateOutboundWhitelistRequest;
use App\Models\OutboundWhitelist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Outbound Whitelist management API controller.
 *
 * Handles CRUD operations for outbound whitelist entries within an organization.
 * All operations are tenant-scoped to the authenticated user's organization.
 */
class OutboundWhitelistController extends Controller
{
    use ApiRequestHandler;

    /**
     * Display a paginated list of outbound whitelist entries.
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

        $this->authorize('viewAny', OutboundWhitelist::class);

        Log::info('Retrieving outbound whitelist list', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
        ]);

        // Build query with organization scope
        $query = OutboundWhitelist::query()
            ->forOrganization($user->organization_id);

        // Apply filters
        if ($request->has('search') && $request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Apply sorting
        $sortField = $request->input('sort', 'created_at');
        $sortOrder = $request->input('order', 'desc');

        // Validate sort field
        $allowedSortFields = ['destination_country', 'destination_prefix', 'outbound_trunk_name', 'created_at', 'updated_at'];
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

        $outboundWhitelist = $query->paginate($perPage);

        Log::info('Outbound whitelist list retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'total' => $outboundWhitelist->total(),
            'per_page' => $perPage,
        ]);

        return response()->json([
            'data' => $outboundWhitelist->items(),
            'meta' => [
                'current_page' => $outboundWhitelist->currentPage(),
                'per_page' => $outboundWhitelist->perPage(),
                'total' => $outboundWhitelist->total(),
                'last_page' => $outboundWhitelist->lastPage(),
                'from' => $outboundWhitelist->firstItem(),
                'to' => $outboundWhitelist->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created outbound whitelist entry.
     *
     * @param StoreOutboundWhitelistRequest $request
     * @return JsonResponse
     */
    public function store(StoreOutboundWhitelistRequest $request): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validated();

        Log::info('Creating new outbound whitelist entry', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'destination_country' => $validated['destination_country'],
            'destination_prefix' => $validated['destination_prefix'] ?? null,
        ]);

        try {
            $outboundWhitelist = DB::transaction(function () use ($user, $validated): OutboundWhitelist {
                // Assign to current user's organization
                $validated['organization_id'] = $user->organization_id;

                // Create outbound whitelist entry
                return OutboundWhitelist::create($validated);
            });

            Log::info('Outbound whitelist entry created successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'outbound_whitelist_id' => $outboundWhitelist->id,
                'destination_country' => $outboundWhitelist->destination_country,
            ]);

            return response()->json([
                'message' => 'Outbound whitelist entry created successfully.',
                'data' => $outboundWhitelist,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create outbound whitelist entry', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to create outbound whitelist entry',
                'message' => 'An error occurred while creating the outbound whitelist entry.',
            ], 500);
        }
    }

    /**
     * Display the specified outbound whitelist entry.
     *
     * @param Request $request
     * @param OutboundWhitelist $outboundWhitelist
     * @return JsonResponse
     */
    public function show(Request $request, OutboundWhitelist $outboundWhitelist): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Tenant scope check
        if ($outboundWhitelist->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant outbound whitelist access attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'outbound_whitelist_id' => $outboundWhitelist->id,
                'target_organization_id' => $outboundWhitelist->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Outbound whitelist entry not found.',
            ], 404);
        }

        Log::info('Outbound whitelist entry details retrieved', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'outbound_whitelist_id' => $outboundWhitelist->id,
        ]);

        return response()->json([
            'data' => $outboundWhitelist,
        ]);
    }

    /**
     * Update the specified outbound whitelist entry.
     *
     * @param UpdateOutboundWhitelistRequest $request
     * @param OutboundWhitelist $outboundWhitelist
     * @return JsonResponse
     */
    public function update(UpdateOutboundWhitelistRequest $request, OutboundWhitelist $outboundWhitelist): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Tenant scope check
        if ($outboundWhitelist->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant outbound whitelist update attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'outbound_whitelist_id' => $outboundWhitelist->id,
                'target_organization_id' => $outboundWhitelist->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Outbound whitelist entry not found.',
            ], 404);
        }

        $validated = $request->validated();

        // Track changed fields for logging
        $changedFields = [];
        foreach ($validated as $key => $value) {
            if ($outboundWhitelist->{$key} != $value) {
                $changedFields[] = $key;
            }
        }

        Log::info('Updating outbound whitelist entry', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'outbound_whitelist_id' => $outboundWhitelist->id,
            'changed_fields' => $changedFields,
        ]);

        try {
            DB::transaction(function () use ($outboundWhitelist, $validated): void {
                // Update outbound whitelist entry
                $outboundWhitelist->update($validated);
            });

            // Reload outbound whitelist entry
            $outboundWhitelist->refresh();

            Log::info('Outbound whitelist entry updated successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'outbound_whitelist_id' => $outboundWhitelist->id,
                'changed_fields' => $changedFields,
            ]);

            return response()->json([
                'message' => 'Outbound whitelist entry updated successfully.',
                'data' => $outboundWhitelist,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update outbound whitelist entry', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'outbound_whitelist_id' => $outboundWhitelist->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to update outbound whitelist entry',
                'message' => 'An error occurred while updating the outbound whitelist entry.',
            ], 500);
        }
    }

    /**
     * Remove the specified outbound whitelist entry.
     *
     * @param Request $request
     * @param OutboundWhitelist $outboundWhitelist
     * @return JsonResponse
     */
    public function destroy(Request $request, OutboundWhitelist $outboundWhitelist): JsonResponse
    {
        $requestId = $this->getRequestId();
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Check authorization using policy
        $this->authorize('delete', $outboundWhitelist);

        // Tenant scope check (policy already verifies this, but keeping for logging)
        if ($outboundWhitelist->organization_id !== $user->organization_id) {
            Log::warning('Cross-tenant outbound whitelist deletion attempt', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'outbound_whitelist_id' => $outboundWhitelist->id,
                'target_organization_id' => $outboundWhitelist->organization_id,
            ]);

            return response()->json([
                'error' => 'Not Found',
                'message' => 'Outbound whitelist entry not found.',
            ], 404);
        }

        Log::info('Deleting outbound whitelist entry', [
            'request_id' => $requestId,
            'user_id' => $user->id,
            'organization_id' => $user->organization_id,
            'outbound_whitelist_id' => $outboundWhitelist->id,
            'destination_country' => $outboundWhitelist->destination_country,
        ]);

        try {
            DB::transaction(function () use ($outboundWhitelist): void {
                // Delete the outbound whitelist entry
                $outboundWhitelist->delete();
            });

            Log::info('Outbound whitelist entry deleted successfully', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'outbound_whitelist_id' => $outboundWhitelist->id,
            ]);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete outbound whitelist entry', [
                'request_id' => $requestId,
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'outbound_whitelist_id' => $outboundWhitelist->id,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);

            return response()->json([
                'error' => 'Failed to delete outbound whitelist entry',
                'message' => 'An error occurred while deleting the outbound whitelist entry.',
            ], 500);
        }
    }
}