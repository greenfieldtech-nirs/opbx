<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CampaignStatus;
use App\Enums\DestinationStatus;
use App\Http\Resources\AutoDialerCampaignResource;
use App\Http\Resources\ListDestinationResource;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Dialer Worker API Controller
 *
 * Provides API endpoints for the Go dialer worker to consume.
 * All endpoints require worker authentication.
 */
class DialerWorkerController extends Controller
{
    /**
     * Get all active campaigns that should be running.
     *
     * Returns campaigns that are:
     * - status = 'active'
     * - Within date range
     * - Within schedule (if current time falls within active hours)
     */
    public function getActiveCampaigns(Request $request): JsonResponse
    {
        $this->authorizeWorker($request);

        $campaigns = AutoDialerCampaign::where('status', CampaignStatus::ACTIVE)
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->get()
            ->filter(fn ($campaign) => $campaign->isRunnable())
            ->values();

        return response()->json([
            'data' => AutoDialerCampaignResource::collection($campaigns),
        ]);
    }

    /**
     * Get pending destinations for a campaign.
     *
     * Returns destinations from lists assigned to the campaign
     * that are in 'pending' status and haven't exceeded max dial attempts.
     */
    public function getPendingDestinations(Request $request, AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorizeWorker($request);

        $limit = $request->input('limit', 50);

        // Get lists assigned to this campaign
        $listIds = AutoDialerList::where('campaign_id', $campaign->id)
            ->pluck('id')
            ->toArray();

        if (empty($listIds)) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'limit' => $limit, 'offset' => 0],
            ]);
        }

        // Get pending destinations
        $destinations = AutoDialerDestination::whereIn('list_id', $listIds)
            ->where('status', DestinationStatus::PENDING)
            ->where('dial_attempts', '<', $campaign->max_dial_attempts)
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => ListDestinationResource::collection($destinations),
            'meta' => [
                'total' => $destinations->count(),
                'limit' => $limit,
                'offset' => 0,
            ],
        ]);
    }

    /**
     * Get destinations that need retry.
     *
     * Returns destinations with retryable dispositions
     * where next retry time has been reached.
     */
    public function getRetryDestinations(Request $request, AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorizeWorker($request);

        $limit = $request->input('limit', 50);

        // Get lists assigned to this campaign
        $listIds = AutoDialerList::where('campaign_id', $campaign->id)
            ->pluck('id')
            ->toArray();

        if (empty($listIds)) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'limit' => $limit, 'offset' => 0],
            ]);
        }

        // Retryable dispositions
        $retryableDispositions = ['busy', 'no-answer', 'cancelled'];

        // Get destinations ready for retry
        $destinations = AutoDialerDestination::whereIn('list_id', $listIds)
            ->whereIn('last_disposition', $retryableDispositions)
            ->where('dial_attempts', '<', $campaign->max_dial_attempts)
            ->where(function ($query) {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->orderBy('next_retry_at', 'asc')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => ListDestinationResource::collection($destinations),
            'meta' => [
                'total' => $destinations->count(),
                'limit' => $limit,
                'offset' => 0,
            ],
        ]);
    }

    /**
     * Initiate a call - creates a call session record.
     */
    public function initiateCall(Request $request): JsonResponse
    {
        $this->authorizeWorker($request);

        $validated = $request->validate([
            'campaign_id' => ['required', 'exists:auto_dialer_campaigns,id'],
            'destination_id' => ['required', 'exists:auto_dialer_destinations,id'],
            'phone_number' => ['required', 'string'],
            'worker_id' => ['required', 'string'],
            'initiated_at' => ['required', 'date'],
        ]);

        $campaign = AutoDialerCampaign::findOrFail($validated['campaign_id']);
        $destination = AutoDialerDestination::findOrFail($validated['destination_id']);

        // Create call session
        $session = AutoDialerCallSession::create([
            'organization_id' => $campaign->organization_id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'phone_number' => $validated['phone_number'],
            'worker_id' => $validated['worker_id'],
            'status' => 'initiated',
            'initiated_at' => $validated['initiated_at'],
        ]);

        // Update destination status
        $destination->update([
            'status' => DestinationStatus::DIALING,
            'dial_attempts' => $destination->dial_attempts + 1,
            'last_dialed_at' => now(),
        ]);

        // Increment campaign pending calls counter
        $campaign->increment('pending_calls');

        Log::info('DialerWorker: Call initiated', [
            'session_id' => $session->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'worker_id' => $validated['worker_id'],
        ]);

        return response()->json([
            'message' => 'Call initiated',
            'data' => [
                'session_id' => $session->id,
                'call_id' => $session->call_id ?? null,
            ],
        ]);
    }

    /**
     * Update call status from Cloudonix webhook.
     */
    public function updateCallStatus(Request $request, AutoDialerCallSession $session): JsonResponse
    {
        $this->authorizeWorker($request);

        $validated = $request->validate([
            'status' => ['required', 'string'],
            'disposition' => ['nullable', 'string'],
            'duration' => ['nullable', 'integer'],
            'billsec' => ['nullable', 'integer'],
            'recording_url' => ['nullable', 'string'],
            'completed_at' => ['nullable', 'date'],
        ]);

        $campaign = $session->campaign;

        // Update session
        $session->update([
            'status' => $validated['status'],
            'disposition' => $validated['disposition'],
            'duration' => $validated['duration'] ?? 0,
            'billsec' => $validated['billsec'] ?? 0,
            'recording_url' => $validated['recording_url'],
            'completed_at' => $validated['completed_at'],
        ]);

        // Update destination
        $destination = $session->destination;
        $destinationData = [
            'last_disposition' => $validated['disposition'],
            'duration' => $validated['duration'] ?? 0,
            'billsec' => $validated['billsec'] ?? 0,
        ];

        // Map disposition to status
        $completedDispositions = ['answered', 'completed'];
        $failedDispositions = ['busy', 'no-answer', 'failed', 'cancelled', 'congestion'];

        if (in_array($validated['disposition'], $completedDispositions, true)) {
            $destinationData['status'] = DestinationStatus::COMPLETED;
            $campaign->increment('completed_calls');
        } elseif (in_array($validated['disposition'], $failedDispositions, true)) {
            // Check if should retry
            $retryableDispositions = ['busy', 'no-answer', 'cancelled'];
            if (in_array($validated['disposition'], $retryableDispositions, true) &&
                $destination->dial_attempts < $campaign->max_dial_attempts) {
                // Calculate next retry time (exponential backoff)
                $nextRetry = $this->calculateNextRetry($destination->dial_attempts);
                $destinationData['next_retry_at'] = $nextRetry;
                $destinationData['status'] = DestinationStatus::PENDING;
            } else {
                $destinationData['status'] = DestinationStatus::FAILED;
                $campaign->increment('failed_calls');
            }
        }

        $destination->update($destinationData);

        // Decrement pending calls
        $campaign->decrement('pending_calls');

        Log::info('DialerWorker: Call status updated', [
            'session_id' => $session->id,
            'status' => $validated['status'],
            'disposition' => $validated['disposition'],
        ]);

        return response()->json([
            'message' => 'Call status updated',
            'data' => [
                'session_id' => $session->id,
                'destination_status' => $destinationData['status']->value,
            ],
        ]);
    }

    /**
     * Pause a campaign (used by circuit breaker or schedule).
     */
    public function pauseCampaign(Request $request, AutoDialerCampaign $campaign): JsonResponse
    {
        $this->authorizeWorker($request);

        $validated = $request->validate([
            'reason' => ['required', 'string'],
            'paused_by' => ['required', 'string'],
            'resume_at' => ['nullable', 'date'],
        ]);

        $campaign->update([
            'status' => CampaignStatus::PAUSED,
        ]);

        // Store pause info in cache
        Cache::put(
            "campaign_pause:{$campaign->id}",
            [
                'reason' => $validated['reason'],
                'paused_by' => $validated['paused_by'],
                'resume_at' => $validated['resume_at'],
                'paused_at' => now()->toIso8601String(),
            ],
            now()->addHours(24)
        );

        Log::info('DialerWorker: Campaign paused', [
            'campaign_id' => $campaign->id,
            'reason' => $validated['reason'],
            'paused_by' => $validated['paused_by'],
        ]);

        return response()->json([
            'message' => 'Campaign paused',
            'data' => [
                'campaign_id' => $campaign->id,
                'status' => 'paused',
            ],
        ]);
    }

    /**
     * Persist worker state for failure recovery.
     */
    public function persistState(Request $request): JsonResponse
    {
        $this->authorizeWorker($request);

        $validated = $request->validate([
            'worker_id' => ['required', 'string'],
            'active_calls' => ['required', 'array'],
            'retry_queue' => ['required', 'array'],
            'campaign_states' => ['required', 'array'],
            'last_updated' => ['required', 'date'],
        ]);

        Cache::put(
            "worker_state:{$validated['worker_id']}",
            $validated,
            now()->addMinutes(10)
        );

        return response()->json([
            'message' => 'State persisted successfully',
        ]);
    }

    /**
     * Get persisted worker state.
     */
    public function getState(Request $request, string $workerId): JsonResponse
    {
        $this->authorizeWorker($request);

        $state = Cache::get("worker_state:{$workerId}");

        if (! $state) {
            return response()->json([
                'error' => 'State not found',
            ], 404);
        }

        return response()->json([
            'data' => $state,
        ]);
    }

    /**
     * Health check endpoint.
     */
    public function health(Request $request): JsonResponse
    {
        $this->authorizeWorker($request);

        // Count active campaigns
        $activeCampaigns = AutoDialerCampaign::where('status', CampaignStatus::ACTIVE)->count();

        // Count active call sessions
        $activeCalls = AutoDialerCallSession::where('status', 'dialing')
            ->orWhere('status', 'connected')
            ->count();

        // Count destinations in retry queue (approximate)
        $retryableDispositions = ['busy', 'no-answer', 'cancelled'];
        $queueDepth = AutoDialerDestination::whereIn('last_disposition', $retryableDispositions)
            ->where(function ($query) {
                $query->whereNull('next_retry_at')
                    ->orWhere('next_retry_at', '<=', now());
            })
            ->count();

        return response()->json([
            'status' => 'healthy',
            'active_campaigns' => $activeCampaigns,
            'active_calls' => $activeCalls,
            'queue_depth' => $queueDepth,
        ]);
    }

    /**
     * Authorize worker requests.
     */
    private function authorizeWorker(Request $request): void
    {
        $token = $request->bearerToken();
        $expectedToken = config('services.dialer_worker.token');

        if (! $token || $token !== $expectedToken) {
            abort(401, 'Unauthorized');
        }
    }

    /**
     * Calculate next retry time using exponential backoff.
     */
    private function calculateNextRetry(int $attemptNumber): string
    {
        $baseDelay = 5; // 5 minutes
        $delay = $baseDelay * (2 ** ($attemptNumber - 1));

        // Cap at 60 minutes
        if ($delay > 60) {
            $delay = 60;
        }

        return now()->addMinutes($delay)->toIso8601String();
    }
}
