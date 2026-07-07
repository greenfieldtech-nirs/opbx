<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CallTrackingCampaignStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\AppliesFilters;
use App\Http\Requests\CallTracking\StoreCampaignRequest;
use App\Http\Requests\CallTracking\UpdateCampaignRequest;
use App\Http\Resources\CallTrackingCampaignResource;
use App\Models\CallTrackingCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Call Tracking Campaign management API controller.
 */
class CallTrackingCampaignController extends Controller
{
    use AppliesFilters;

    private const int DEFAULT_PER_PAGE = 20;

    private const int MAX_PER_PAGE = 100;

    /**
     * Display a paginated list of campaigns.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->authorize('viewAny', CallTrackingCampaign::class);

        $query = CallTrackingCampaign::query()
            ->forOrganization($user->organization_id)
            ->withCount('trackingNumbers');

        $this->applyFilters($query, $request, $this->getFilterConfig());

        $sortField = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['name', 'status', 'source', 'medium', 'created_at', 'updated_at'];
        if (! in_array($sortField, $allowedSortFields, true)) {
            $sortField = 'created_at';
        }

        $sortOrder = in_array(strtolower($sortOrder), ['asc', 'desc'], true)
            ? strtolower($sortOrder)
            : 'desc';

        $query->orderBy($sortField, $sortOrder);

        $perPage = (int) $request->input('per_page', self::DEFAULT_PER_PAGE);
        $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

        $campaigns = $query->paginate($perPage);

        return response()->json([
            'data' => CallTrackingCampaignResource::collection($campaigns)->resolve(),
            'meta' => [
                'current_page' => $campaigns->currentPage(),
                'per_page' => $campaigns->perPage(),
                'total' => $campaigns->total(),
                'last_page' => $campaigns->lastPage(),
                'from' => $campaigns->firstItem(),
                'to' => $campaigns->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created campaign.
     */
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $this->authorize('create', CallTrackingCampaign::class);

        $user = $request->user();
        $validated = $request->validated();

        try {
            $campaign = DB::transaction(function () use ($user, $validated): CallTrackingCampaign {
                $validated['organization_id'] = $user->organization_id;

                return CallTrackingCampaign::create($validated);
            });

            Log::info('Call tracking campaign created', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'campaign_id' => $campaign->id,
            ]);

            return response()->json([
                'message' => 'Call tracking campaign created successfully.',
                'data' => new CallTrackingCampaignResource($campaign),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create call tracking campaign', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to create call tracking campaign',
                'message' => 'An error occurred while creating the campaign.',
            ], 500);
        }
    }

    /**
     * Display the specified campaign.
     */
    public function show(Request $request, CallTrackingCampaign $callTrackingCampaign): JsonResponse
    {
        $user = $request->user();

        $this->authorize('view', $callTrackingCampaign);

        if ($callTrackingCampaign->organization_id !== $user->organization_id) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Call tracking campaign not found.',
            ], 404);
        }

        $callTrackingCampaign->loadCount('trackingNumbers');

        return response()->json([
            'data' => new CallTrackingCampaignResource($callTrackingCampaign),
        ]);
    }

    /**
     * Update the specified campaign.
     */
    public function update(UpdateCampaignRequest $request, CallTrackingCampaign $callTrackingCampaign): JsonResponse
    {
        $this->authorize('update', $callTrackingCampaign);

        $user = $request->user();
        $validated = $request->validated();

        if ($callTrackingCampaign->organization_id !== $user->organization_id) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Call tracking campaign not found.',
            ], 404);
        }

        try {
            $callTrackingCampaign->update($validated);
            $callTrackingCampaign->refresh();

            Log::info('Call tracking campaign updated', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'campaign_id' => $callTrackingCampaign->id,
            ]);

            return response()->json([
                'message' => 'Call tracking campaign updated successfully.',
                'data' => new CallTrackingCampaignResource($callTrackingCampaign),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update call tracking campaign', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'campaign_id' => $callTrackingCampaign->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to update call tracking campaign',
                'message' => 'An error occurred while updating the campaign.',
            ], 500);
        }
    }

    /**
     * Remove the specified campaign.
     */
    public function destroy(Request $request, CallTrackingCampaign $callTrackingCampaign): JsonResponse
    {
        $user = $request->user();

        $this->authorize('delete', $callTrackingCampaign);

        if ($callTrackingCampaign->organization_id !== $user->organization_id) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Call tracking campaign not found.',
            ], 404);
        }

        try {
            $callTrackingCampaign->delete();

            Log::info('Call tracking campaign deleted', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'campaign_id' => $callTrackingCampaign->id,
            ]);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete call tracking campaign', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'campaign_id' => $callTrackingCampaign->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to delete call tracking campaign',
                'message' => 'An error occurred while deleting the campaign.',
            ], 500);
        }
    }

    /**
     * Get the filter configuration for the index method.
     *
     * @return array<string, array>
     */
    private function getFilterConfig(): array
    {
        return [
            'status' => [
                'type' => 'enum',
                'enum' => CallTrackingCampaignStatus::class,
                'scope' => 'withStatus',
            ],
            'source' => [
                'type' => 'exact',
                'column' => 'source',
            ],
            'medium' => [
                'type' => 'exact',
                'column' => 'medium',
            ],
            'search' => [
                'type' => 'search',
                'scope' => 'search',
            ],
        ];
    }
}
