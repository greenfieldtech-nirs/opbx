<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CallTrackingEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\CallTracking\NotificationLogIndexRequest;
use App\Http\Requests\CallTracking\StoreNotificationSettingsRequest;
use App\Http\Requests\CallTracking\TestNotificationSettingsRequest;
use App\Http\Resources\CallTrackingNotificationLogResource;
use App\Http\Resources\CallTrackingNotificationSettingsResource;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNotificationLog;
use App\Models\CallTrackingNotificationSettings;
use App\Models\CallTrackingSession;
use App\Services\CallTracking\CallTrackingWebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    /**
     * Send a test notification for the campaign.
     */
    public function test(
        TestNotificationSettingsRequest $request,
        CallTrackingCampaign $callTrackingCampaign,
        CallTrackingWebhookDispatcher $dispatcher
    ): JsonResponse {
        $settings = CallTrackingNotificationSettings::forCampaign($callTrackingCampaign->id)->first();

        if (! $settings || ! $settings->isConfigured()) {
            return response()->json([
                'error' => 'Unprocessable Content',
                'message' => 'Notification settings are missing, inactive, or have no webhook URL configured.',
            ], 422);
        }

        $eventType = $request->input('event_type', CallTrackingEventType::CALL_RECEIVED->value);
        $eventId = 'ct_test_'.Str::uuid();

        $session = new CallTrackingSession([
            'organization_id' => $callTrackingCampaign->organization_id,
            'call_tracking_campaign_id' => $callTrackingCampaign->id,
            'call_tracking_number_id' => null,
            'did_number_id' => null,
            'call_id' => 'test_call_'.Str::random(8),
            'session_id' => null,
            'caller_number' => '+15550000000',
            'called_number' => '+15551111111',
            'source' => $callTrackingCampaign->source,
            'medium' => $callTrackingCampaign->medium,
            'campaign_name' => $callTrackingCampaign->name,
            'disposition' => 'ANSWERED',
            'duration' => 72,
            'billsec' => 70,
            'is_answered' => true,
            'is_converted' => true,
            'conversion_value' => null,
        ]);

        $log = $dispatcher->dispatch($settings, $session, $eventType, $eventId);

        return response()->json([
            'data' => new CallTrackingNotificationLogResource($log),
        ]);
    }

    /**
     * List notification delivery logs for the campaign.
     */
    public function logs(
        NotificationLogIndexRequest $request,
        CallTrackingCampaign $callTrackingCampaign
    ): JsonResponse {
        $validated = $request->validated();

        $query = CallTrackingNotificationLog::query()
            ->where('organization_id', $callTrackingCampaign->organization_id)
            ->where('call_tracking_campaign_id', $callTrackingCampaign->id);

        if (! empty($validated['event_type'])) {
            $query->where('event_type', $validated['event_type']);
        }

        if (isset($validated['success'])) {
            $query->where('is_success', (bool) $validated['success']);
        }

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }

        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json([
            'data' => CallTrackingNotificationLogResource::collection($logs->items()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }
}
