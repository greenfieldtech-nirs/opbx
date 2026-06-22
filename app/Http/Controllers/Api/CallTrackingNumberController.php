<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CallTracking\StoreNumberRequest;
use App\Http\Requests\CallTracking\UpdateNumberRequest;
use App\Http\Resources\CallTrackingNumberResource;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Call Tracking Number management API controller.
 *
 * Numbers are nested under campaigns.
 */
class CallTrackingNumberController extends Controller
{
    /**
     * Display a list of tracking numbers for a campaign.
     */
    public function index(Request $request, CallTrackingCampaign $callTrackingCampaign): JsonResponse
    {
        $this->authorize('viewAny', CallTrackingNumber::class);

        $user = $request->user();

        if ($callTrackingCampaign->organization_id !== $user->organization_id) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Call tracking campaign not found.',
            ], 404);
        }

        $numbers = CallTrackingNumber::with('did')
            ->where('call_tracking_campaign_id', $callTrackingCampaign->id)
            ->where('organization_id', $user->organization_id)
            ->get();

        return response()->json([
            'data' => CallTrackingNumberResource::collection($numbers)->resolve(),
        ]);
    }

    /**
     * Store a newly created tracking number.
     */
    public function store(StoreNumberRequest $request, CallTrackingCampaign $callTrackingCampaign): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        if ($callTrackingCampaign->organization_id !== $user->organization_id) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Call tracking campaign not found.',
            ], 404);
        }

        try {
            $number = DB::transaction(function () use ($user, $callTrackingCampaign, $validated): CallTrackingNumber {
                $number = CallTrackingNumber::create([
                    'organization_id' => $user->organization_id,
                    'call_tracking_campaign_id' => $callTrackingCampaign->id,
                    'did_number_id' => $validated['did_number_id'],
                    'friendly_name' => $validated['friendly_name'] ?? null,
                    'status' => $validated['status'] ?? 'active',
                ]);

                // Configure the DID to route calls to this campaign
                $did = $number->did;
                $did->update([
                    'routing_type' => 'call_tracking',
                    'routing_config' => ['call_tracking_campaign_id' => $callTrackingCampaign->id],
                ]);

                return $number;
            });

            $number->load('did');

            Log::info('Call tracking number created', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'campaign_id' => $callTrackingCampaign->id,
                'number_id' => $number->id,
                'did_number_id' => $number->did_number_id,
            ]);

            return response()->json([
                'message' => 'Call tracking number created successfully.',
                'data' => new CallTrackingNumberResource($number),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Failed to create call tracking number', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'campaign_id' => $callTrackingCampaign->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to create call tracking number',
                'message' => 'An error occurred while creating the tracking number.',
            ], 500);
        }
    }

    /**
     * Update the specified tracking number.
     */
    public function update(
        UpdateNumberRequest $request,
        CallTrackingCampaign $callTrackingCampaign,
        CallTrackingNumber $callTrackingNumber
    ): JsonResponse {
        $user = $request->user();
        $validated = $request->validated();

        if ($callTrackingCampaign->organization_id !== $user->organization_id
            || $callTrackingNumber->organization_id !== $user->organization_id
            || $callTrackingNumber->call_tracking_campaign_id !== $callTrackingCampaign->id
        ) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Call tracking number not found.',
            ], 404);
        }

        try {
            $callTrackingNumber->update($validated);
            $callTrackingNumber->refresh();
            $callTrackingNumber->load('did');

            Log::info('Call tracking number updated', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'number_id' => $callTrackingNumber->id,
            ]);

            return response()->json([
                'message' => 'Call tracking number updated successfully.',
                'data' => new CallTrackingNumberResource($callTrackingNumber),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update call tracking number', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'number_id' => $callTrackingNumber->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to update call tracking number',
                'message' => 'An error occurred while updating the tracking number.',
            ], 500);
        }
    }

    /**
     * Remove the specified tracking number.
     */
    public function destroy(
        Request $request,
        CallTrackingCampaign $callTrackingCampaign,
        CallTrackingNumber $callTrackingNumber
    ): JsonResponse {
        $user = $request->user();

        $this->authorize('delete', $callTrackingNumber);

        if ($callTrackingCampaign->organization_id !== $user->organization_id
            || $callTrackingNumber->organization_id !== $user->organization_id
            || $callTrackingNumber->call_tracking_campaign_id !== $callTrackingCampaign->id
        ) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Call tracking number not found.',
            ], 404);
        }

        try {
            $callTrackingNumber->delete();

            Log::info('Call tracking number deleted', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'number_id' => $callTrackingNumber->id,
            ]);

            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete call tracking number', [
                'user_id' => $user->id,
                'organization_id' => $user->organization_id,
                'number_id' => $callTrackingNumber->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to delete call tracking number',
                'message' => 'An error occurred while deleting the tracking number.',
            ], 500);
        }
    }
}
