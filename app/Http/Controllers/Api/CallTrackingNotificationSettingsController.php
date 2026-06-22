<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CallTracking\StoreNotificationSettingsRequest;
use App\Http\Resources\CallTrackingNotificationSettingsResource;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNotificationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Call Tracking notification settings API controller.
 *
 * Exposes the singleton notification settings resource nested under a campaign.
 */
class CallTrackingNotificationSettingsController extends Controller
{
    /**
     * Display the notification settings for a campaign.
     */
    public function show(Request $request, CallTrackingCampaign $callTrackingCampaign): JsonResponse
    {
        $settings = CallTrackingNotificationSettings::forCampaign($callTrackingCampaign->id)->first();

        $this->authorize(
            'view',
            $settings ?? new CallTrackingNotificationSettings([
                'organization_id' => $callTrackingCampaign->organization_id,
                'call_tracking_campaign_id' => $callTrackingCampaign->id,
            ])
        );

        if ($settings === null) {
            return response()->json([
                'error' => 'Not Found',
                'message' => 'Notification settings not found.',
            ], 404);
        }

        return response()->json([
            'data' => new CallTrackingNotificationSettingsResource($settings),
        ]);
    }

    /**
     * Update or create the notification settings for a campaign.
     */
    public function update(
        StoreNotificationSettingsRequest $request,
        CallTrackingCampaign $callTrackingCampaign
    ): JsonResponse {
        $user = $request->user();
        $validated = $request->validated();

        try {
            $settings = CallTrackingNotificationSettings::updateOrCreate(
                ['call_tracking_campaign_id' => $callTrackingCampaign->id],
                array_merge(
                    ['organization_id' => $callTrackingCampaign->organization_id],
                    $validated
                )
            );

            Log::info('Call tracking notification settings updated', [
                'user_id' => $user->id,
                'organization_id' => $callTrackingCampaign->organization_id,
                'campaign_id' => $callTrackingCampaign->id,
                'settings_id' => $settings->id,
            ]);

            return response()->json([
                'message' => 'Notification settings updated successfully.',
                'data' => new CallTrackingNotificationSettingsResource($settings),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update call tracking notification settings', [
                'user_id' => $user->id,
                'organization_id' => $callTrackingCampaign->organization_id,
                'campaign_id' => $callTrackingCampaign->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to update notification settings',
                'message' => 'An error occurred while updating the notification settings.',
            ], 500);
        }
    }
}
