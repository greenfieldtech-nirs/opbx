<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DestinationStatus;
use App\Http\Resources\AutoDialerCampaignResource;
use App\Http\Resources\ListDestinationResource;
use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Services\AutoDialer\AutoDialerCloudonixService;
use App\Services\AutoDialer\CallSessionService;
use App\Services\AutoDialer\CampaignQueryService;
use App\Services\AutoDialer\CxmlGenerationService;
use App\Services\AutoDialer\DestinationManagementService;
use App\Services\AutoDialer\DialerWorkerCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Dialer Worker API Controller
 *
 * Provides API endpoints for the Go dialer worker to consume.
 * All endpoints require worker authentication.
 *
 * This controller delegates all business logic to specialized services:
 * - CampaignQueryService: Campaign queries and filtering
 * - CallSessionService: Call session management
 * - CxmlGenerationService: CXML generation for routing
 * - DialerWorkerCampaignService: Campaign lifecycle operations
 * - DestinationManagementService: Destination queries and updates
 */
class DialerWorkerController extends Controller
{
    public function __construct(
        private readonly CampaignQueryService $campaignQueryService,
        private readonly CallSessionService $callSessionService,
        private readonly CxmlGenerationService $cxmlGenerationService,
        private readonly DialerWorkerCampaignService $campaignService,
        private readonly DestinationManagementService $destinationService,
    ) {}

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

        $campaigns = $this->campaignQueryService->getActiveRunnableCampaigns();

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
    public function getPendingDestinations(Request $request, int $campaign): JsonResponse
    {
        $this->authorizeWorker($request);

        $limit = (int) $request->input('limit', 50);

        $campaignModel = $this->campaignQueryService->findById($campaign);

        if (! $campaignModel) {
            return response()->json([
                'error' => 'Campaign not found',
            ], 404);
        }

        $destinations = $this->destinationService->getPendingDestinations($campaign, $limit);

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
    public function getRetryDestinations(Request $request, int $campaign): JsonResponse
    {
        $this->authorizeWorker($request);

        $limit = (int) $request->input('limit', 50);

        $campaignModel = $this->campaignQueryService->findById($campaign);

        if (! $campaignModel) {
            return response()->json([
                'error' => 'Campaign not found',
            ], 404);
        }

        $destinations = $this->destinationService->getRetryDestinations($campaign, $limit);

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
    public function initiateCall(Request $request, AutoDialerCloudonixService $cloudonixService): JsonResponse
    {
        $this->authorizeWorker($request);

        $validated = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'destination_id' => ['required', 'integer'],
            'phone_number' => ['required', 'string'],
            'worker_id' => ['required', 'string'],
            'initiated_at' => ['required', 'date'],
            'caller_id' => ['nullable', 'string'],
            'caller_did_id' => ['nullable', 'integer'],
        ]);

        $campaign = $this->campaignQueryService->findById($validated['campaign_id']);
        $destination = $this->destinationService->findById($validated['destination_id']);

        if (! $campaign || ! $destination) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'campaign_id' => ! $campaign ? ['The selected campaign id is invalid.'] : [],
                    'destination_id' => ! $destination ? ['The selected destination id is invalid.'] : [],
                ],
            ], 422);
        }

        // Get Cloudonix settings for the organization
        $cloudonixSettings = $campaign->organization->cloudonixSettings;

        if (! $cloudonixSettings || ! $cloudonixSettings->isConfigured()) {
            Log::error('DialerWorker: Cloudonix not configured for organization', [
                'campaign_id' => $campaign->id,
                'organization_id' => $campaign->organization_id,
            ]);

            return response()->json([
                'message' => 'Cloudonix not configured for this organization',
            ], 422);
        }

        // Generate webhook callback URL for Cloudonix
        $webhookBaseUrl = $cloudonixSettings->webhook_base_url ?? config('app.webhook_base_url') ?? config('app.url');
        $callbackUrl = rtrim($webhookBaseUrl, '/').'/api/webhooks/cloudonix/call-status';

        // Initiate the call via Cloudonix API with selected Caller ID
        $result = $cloudonixService->initiateCall(
            $campaign,
            $destination,
            $cloudonixSettings,
            $callbackUrl,
            $validated['caller_id'] ?? null,
            $validated['caller_did_id'] ?? null
        );

        if (! $result['success']) {
            Log::error('DialerWorker: Failed to initiate call via Cloudonix', [
                'campaign_id' => $campaign->id,
                'destination_id' => $destination->id,
                'error' => $result['error'],
            ]);

            return response()->json([
                'message' => 'Failed to initiate call: '.$result['error'],
            ], 500);
        }

        // Create call session
        $session = $this->callSessionService->createSession($campaign, $destination, [
            'session_token' => $result['session_token'] ?? 'sess-'.uniqid(),
            'call_id' => $result['call_id'] ?? null,
            'phone_number' => $validated['phone_number'],
            'caller_id' => $validated['caller_id'] ?? $campaign->caller_id,
            'caller_did_id' => $validated['caller_did_id'] ?? null,
            'worker_id' => $validated['worker_id'],
            'initiated_at' => $validated['initiated_at'],
        ]);

        // Update destination status
        $this->destinationService->markAsDialing($destination);

        // Increment campaign pending calls counter
        $this->campaignService->incrementPendingCalls($campaign);

        Log::info('DialerWorker: Call initiated successfully', [
            'session_id' => $session->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'worker_id' => $validated['worker_id'],
            'call_id' => $result['call_id'],
        ]);

        return response()->json([
            'message' => 'Call initiated',
            'data' => [
                'session_id' => $session->id,
                'call_id' => $result['call_id'],
                'caller_id' => $validated['caller_id'] ?? $campaign->caller_id,
                'caller_did_id' => $validated['caller_did_id'] ?? null,
                'callback_url' => $callbackUrl,
            ],
        ], 201);
    }

    /**
     * Update call status from Cloudonix webhook.
     */
    public function updateCallStatus(Request $request, int $session): JsonResponse
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

        $sessionModel = $this->callSessionService->findWithRelations($session);

        if (! $sessionModel) {
            return response()->json([
                'error' => 'Session not found',
            ], 404);
        }

        $campaign = $sessionModel->campaign;
        $destination = $sessionModel->destination;

        // Update session
        $this->callSessionService->updateSession($sessionModel, [
            'status' => $validated['status'],
            'disposition' => $validated['disposition'],
            'duration' => $validated['duration'] ?? 0,
            'billsec' => $validated['billsec'] ?? 0,
            'recording_url' => $validated['recording_url'] ?? null,
            'completed_at' => $validated['completed_at'] ?? null,
        ]);

        // Handle destination status based on disposition
        $this->handleDispositionUpdate($campaign, $destination, $validated);

        // Decrement pending calls
        $this->campaignService->decrementPendingCalls($campaign);

        Log::info('DialerWorker: Call status updated', [
            'session_id' => $sessionModel->id,
            'status' => $validated['status'],
            'disposition' => $validated['disposition'] ?? null,
        ]);

        return response()->json([
            'message' => 'Call status updated',
            'data' => [
                'session_id' => $sessionModel->id,
                'destination_status' => $this->getDestinationStatusFromDisposition($validated['disposition'] ?? null)->value,
            ],
        ]);
    }

    /**
     * Set final disposition for a call and handle retry logic.
     *
     * This endpoint is used by the worker to explicitly set disposition
     * and control retry behavior.
     */
    public function setDisposition(Request $request, int $session): JsonResponse
    {
        $this->authorizeWorker($request);

        $validated = $request->validate([
            'disposition' => ['required', 'string', 'in:answered,completed,busy,no-answer,failed,cancelled,congestion'],
            'should_retry' => ['required', 'boolean'],
            'next_retry_at' => ['nullable', 'date'],
            'attempt_number' => ['required', 'integer', 'min:1'],
            'duration' => ['nullable', 'integer'],
            'billsec' => ['nullable', 'integer'],
        ]);

        $sessionModel = $this->callSessionService->findWithRelations($session);

        if (! $sessionModel) {
            return response()->json([
                'error' => 'Session not found',
            ], 404);
        }

        $campaign = $sessionModel->campaign;
        $destination = $sessionModel->destination;

        if (! $campaign || ! $destination) {
            return response()->json([
                'error' => 'Campaign or destination not found',
            ], 404);
        }

        // Handle the disposition
        $destinationStatus = $this->processDisposition($sessionModel, $campaign, $destination, $validated);

        Log::info('DialerWorker: Disposition set', [
            'session_id' => $sessionModel->id,
            'disposition' => $validated['disposition'],
            'should_retry' => $validated['should_retry'],
            'attempt_number' => $validated['attempt_number'],
        ]);

        return response()->json([
            'message' => 'Disposition set successfully',
            'data' => [
                'session_id' => $sessionModel->id,
                'disposition' => $validated['disposition'],
                'destination_status' => $destinationStatus->value,
                'will_retry' => $validated['should_retry'] && isset($validated['next_retry_at']),
            ],
        ]);
    }

    /**
     * Pause a campaign (used by circuit breaker or schedule).
     */
    public function pauseCampaign(Request $request, int $campaign): JsonResponse
    {
        $this->authorizeWorker($request);

        $validated = $request->validate([
            'reason' => ['required', 'string'],
            'paused_by' => ['required', 'string'],
            'resume_at' => ['nullable', 'date'],
        ]);

        $campaignModel = $this->campaignQueryService->findById($campaign);

        if (! $campaignModel) {
            return response()->json([
                'error' => 'Campaign not found',
            ], 404);
        }

        $result = $this->campaignService->pauseCampaign($campaignModel, $validated);

        return response()->json([
            'message' => 'Campaign paused',
            'data' => $result,
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
            'active_calls' => ['present', 'array'],
            'retry_queue' => ['present', 'array'],
            'campaign_states' => ['present', 'array'],
            'last_updated' => ['required', 'date'],
        ]);

        $this->campaignService->persistWorkerState($validated['worker_id'], $validated);

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

        $state = $this->campaignService->getWorkerState($workerId);

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

        $activeCampaigns = $this->campaignQueryService->countActive();
        $activeCalls = $this->callSessionService->countActive();
        $queueDepth = $this->destinationService->countGlobalRetryQueue();

        return response()->json([
            'status' => 'healthy',
            'active_campaigns' => $activeCampaigns,
            'active_calls' => $activeCalls,
            'queue_depth' => $queueDepth,
        ]);
    }

    /**
     * Generate CXML for outbound call routing.
     */
    public function generateCxml(Request $request): JsonResponse
    {
        $this->authorizeWorker($request);

        $validated = $request->validate([
            'campaign_id' => ['required', 'integer'],
            'session_id' => ['required', 'integer'],
            'phone_number' => ['required', 'string'],
            'call_sid' => ['required', 'string'],
        ]);

        $campaign = $this->campaignQueryService->findWithRelations(
            $validated['campaign_id'],
            ['aiAssistant', 'aiLoadBalancer']
        );

        if (! $campaign) {
            return response()->json([
                'error' => 'Campaign not found',
            ], 404);
        }

        try {
            $cxml = $this->cxmlGenerationService->generateRoutingCxml($campaign, $validated);

            Log::info('DialerWorker: Generated CXML for outbound call', [
                'campaign_id' => $campaign->id,
                'session_id' => $validated['session_id'],
                'routing_type' => $campaign->routing_destination_type?->value,
            ]);

            return response()->json([
                'data' => [
                    'cxml' => $cxml,
                    'routing_type' => $campaign->routing_destination_type?->value,
                ],
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            Log::error('DialerWorker: Failed to generate CXML', [
                'campaign_id' => $campaign->id,
                'session_id' => $validated['session_id'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to generate CXML: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Authorize worker requests.
     */
    private function authorizeWorker(Request $request): void
    {
        $token = $request->bearerToken();
        $expectedToken = config('services.dialer_worker.token');
        $secondaryToken = config('services.dialer_worker.token_secondary');

        // Check primary token
        $isValid = $token && hash_equals($expectedToken, $token);

        // Check secondary token (for rotation)
        $isSecondaryValid = $token && ! empty($secondaryToken) && hash_equals($secondaryToken, $token);

        if (! $isValid && ! $isSecondaryValid) {
            abort(401, 'Unauthorized');
        }
    }

    /**
     * Handle disposition update for a destination.
     *
     * @param  AutoDialerCampaign  $campaign
     * @param  AutoDialerDestination  $destination
     * @param  array<string, mixed>  $validated
     */
    private function handleDispositionUpdate($campaign, $destination, array $validated): void
    {
        $disposition = $validated['disposition'] ?? null;
        $duration = $validated['duration'] ?? 0;
        $billsec = $validated['billsec'] ?? 0;

        if ($disposition === null) {
            // No disposition provided, just update the session status
            return;
        }

        $destinationData = [
            'disposition' => $disposition,
            'duration' => $duration,
            'billsec' => $billsec,
        ];

        if ($this->campaignService->isCompletedDisposition($disposition)) {
            $destinationData['status'] = DestinationStatus::COMPLETED;
            $this->campaignService->incrementCompletedCalls($campaign);
        } elseif ($this->campaignService->isFailedDisposition($disposition)) {
            if ($this->campaignService->isRetryableDisposition($disposition) &&
                ! $this->destinationService->hasReachedMaxAttempts($destination, $campaign->max_dial_attempts)) {
                $destinationData['next_retry_at'] = $this->campaignService->calculateNextRetry($destination->dial_attempts);
                $destinationData['status'] = DestinationStatus::PENDING;
            } else {
                $destinationData['status'] = DestinationStatus::FAILED;
                $this->campaignService->incrementFailedCalls($campaign);
            }
        } else {
            $destinationData['status'] = $destination->status;
        }

        $this->destinationService->updateDisposition($destination, $destinationData);
    }

    /**
     * Process disposition and return the resulting destination status.
     *
     * @param  AutoDialerCallSession  $session
     * @param  AutoDialerCampaign  $campaign
     * @param  AutoDialerDestination  $destination
     * @param  array<string, mixed>  $validated
     */
    private function processDisposition($session, $campaign, $destination, array $validated): DestinationStatus
    {
        $disposition = $validated['disposition'];
        $shouldRetry = $validated['should_retry'];
        $nextRetryAt = $validated['next_retry_at'] ?? null;

        // Determine destination status first
        if ($this->campaignService->isCompletedDisposition($disposition)) {
            $this->destinationService->markAsCompleted($destination, $disposition, $validated['duration'] ?? 0, $validated['billsec'] ?? 0);
            $this->campaignService->incrementCompletedCalls($campaign);
            $sessionStatus = 'completed';
            $destStatus = DestinationStatus::COMPLETED;
        } elseif ($shouldRetry && $nextRetryAt) {
            $this->destinationService->scheduleRetry($destination, $nextRetryAt, $disposition);
            $sessionStatus = 'failed';
            $destStatus = DestinationStatus::PENDING;

            Log::info('DialerWorker: Destination scheduled for retry', [
                'session_id' => $session->id,
                'destination_id' => $destination->id,
                'attempt_number' => $validated['attempt_number'],
                'next_retry_at' => $nextRetryAt,
            ]);
        } else {
            $this->destinationService->markAsFailed($destination, $disposition, $validated['duration'] ?? 0, $validated['billsec'] ?? 0);
            $this->campaignService->incrementFailedCalls($campaign);
            $sessionStatus = 'failed';
            $destStatus = DestinationStatus::FAILED;
        }

        // Update session
        $this->callSessionService->setDisposition($session, [
            'disposition' => $disposition,
            'duration' => $validated['duration'] ?? 0,
            'billsec' => $validated['billsec'] ?? 0,
            'status' => $sessionStatus,
        ]);

        // Decrement pending calls
        $this->campaignService->decrementPendingCalls($campaign);

        return $destStatus;
    }

    /**
     * Get destination status from disposition string.
     */
    private function getDestinationStatusFromDisposition(?string $disposition): DestinationStatus
    {
        if ($disposition === null) {
            return DestinationStatus::PENDING;
        }

        if ($this->campaignService->isCompletedDisposition($disposition)) {
            return DestinationStatus::COMPLETED;
        }

        if (in_array($disposition, ['failed', 'congestion'], true)) {
            return DestinationStatus::FAILED;
        }

        return DestinationStatus::PENDING;
    }
}
