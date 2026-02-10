<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CallNotifications\StoreSettingsRequest;
use App\Http\Requests\CallNotifications\UpdateSettingsRequest;
use App\Http\Resources\CallNotificationsSettingsResource;
use App\Models\CallNotificationLog;
use App\Models\CallNotificationsSettings;
use App\Services\CallNotifications\WebhookDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Call Notifications Settings API Controller
 *
 * Manages webhook notification settings and delivery logs.
 */
class CallNotificationsSettingsController extends Controller
{
    public function __construct(
        private readonly WebhookDispatcher $webhookDispatcher
    ) {}

    /**
     * Get notification settings for the organization.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = CallNotificationsSettings::forOrganization($user->organization_id)->first();

        if (! $settings) {
            return response()->json([
                'data' => null,
                'message' => 'Notification settings not configured',
            ], 200);
        }

        return response()->json([
            'data' => new CallNotificationsSettingsResource($settings),
        ]);
    }

    /**
     * Store new notification settings.
     */
    public function store(StoreSettingsRequest $request): JsonResponse
    {
        $user = $request->user();

        // Check if settings already exist
        $existing = CallNotificationsSettings::forOrganization($user->organization_id)->first();
        if ($existing) {
            return response()->json([
                'error' => 'Notification settings already exist. Use PUT to update.',
            ], 409);
        }

        $settings = CallNotificationsSettings::create([
            'organization_id' => $user->organization_id,
            ...$request->validated(),
        ]);

        Log::info('Call notification settings created', [
            'organization_id' => $user->organization_id,
            'settings_id' => $settings->id,
        ]);

        return response()->json([
            'data' => new CallNotificationsSettingsResource($settings),
            'message' => 'Notification settings created successfully',
        ], 201);
    }

    /**
     * Update notification settings.
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $user = $request->user();
        $settings = CallNotificationsSettings::forOrganization($user->organization_id)->first();

        if (! $settings) {
            return response()->json([
                'error' => 'Notification settings not found. Use POST to create.',
            ], 404);
        }

        $settings->update($request->validated());

        Log::info('Call notification settings updated', [
            'organization_id' => $user->organization_id,
            'settings_id' => $settings->id,
        ]);

        return response()->json([
            'data' => new CallNotificationsSettingsResource($settings),
            'message' => 'Notification settings updated successfully',
        ]);
    }

    /**
     * Delete notification settings.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = CallNotificationsSettings::forOrganization($user->organization_id)->first();

        if (! $settings) {
            return response()->json([
                'error' => 'Notification settings not found',
            ], 404);
        }

        $settings->delete();

        Log::info('Call notification settings deleted', [
            'organization_id' => $user->organization_id,
        ]);

        return response()->json([
            'message' => 'Notification settings deleted successfully',
        ]);
    }

    /**
     * Test webhook delivery.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = CallNotificationsSettings::forOrganization($user->organization_id)->first();

        if (! $settings || ! $settings->isConfigured()) {
            return response()->json([
                'error' => 'Notification settings not configured',
            ], 400);
        }

        // Create test payload
        $testPayload = [
            'event_type' => 'call.status_update',
            'event_id' => 'test-'.uniqid(),
            'timestamp' => now()->toIso8601String(),
            'organization_id' => $user->organization_id,
            'session' => [
                'call_session_token' => 'test-session-'.uniqid(),
                'from' => '+12125551234',
                'to' => '+12125555678',
                'direction' => 'inbound',
                'call_start_time' => now()->toIso8601String(),
                'call_answer_time' => null,
                'call_end_time' => null,
                'call_duration' => 0,
                'call_billable_duration' => 0,
                'status' => 'new',
                'previous_status' => 'unknown',
            ],
            'metadata' => [
                'caller_name' => 'Test Caller',
                'extension_id' => null,
                'did_id' => null,
            ],
            'test' => true,
        ];

        try {
            $success = $this->webhookDispatcher->dispatch(
                $settings,
                $testPayload,
                $testPayload['event_id'],
                $testPayload['session']['call_session_token']
            );

            if ($success) {
                return response()->json([
                    'message' => 'Test webhook delivered successfully',
                    'webhook_url' => $settings->webhook_url,
                ]);
            }

            return response()->json([
                'error' => 'Test webhook delivery failed',
                'webhook_url' => $settings->webhook_url,
            ], 502);

        } catch (\Exception $e) {
            Log::error('Test webhook delivery failed', [
                'organization_id' => $user->organization_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Test webhook delivery failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get delivery logs.
     */
    public function logs(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = CallNotificationLog::forOrganization($user->organization_id)
            ->orderBy('created_at', 'desc');

        // Filter by session token
        if ($request->has('session_token')) {
            $query->forSession($request->input('session_token'));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // Filter by success/failure
        if ($request->has('success')) {
            if ($request->boolean('success')) {
                $query->successful();
            } else {
                $query->failed();
            }
        }

        // Filter by date range
        if ($request->has('from')) {
            $query->where('created_at', '>=', $request->input('from'));
        }
        if ($request->has('to')) {
            $query->where('created_at', '<=', $request->input('to'));
        }

        $logs = $query->paginate($request->input('per_page', 50));

        return response()->json([
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    /**
     * Get rate limit status.
     */
    public function rateLimit(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $this->webhookDispatcher->getRateLimitStatus($user->organization_id);

        return response()->json([
            'data' => $status,
        ]);
    }
}
