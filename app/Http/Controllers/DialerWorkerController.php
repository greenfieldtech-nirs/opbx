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
use App\Scopes\OrganizationScope;
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

        // Bypass organization scope for worker API
        $campaigns = OrganizationScope::bypass(function () {
            return AutoDialerCampaign::with('organization.cloudonixSettings')
                ->where('status', CampaignStatus::ACTIVE)
                ->whereDate('start_date', '<=', now())
                ->whereDate('end_date', '>=', now())
                ->get()
                ->filter(fn ($campaign) => $campaign->isRunnable())
                ->values();
        });

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

        $limit = $request->input('limit', 50);

        // Bypass organization scope and find campaign
        $campaignModel = OrganizationScope::bypass(
            fn () => AutoDialerCampaign::find($campaign)
        );

        if (! $campaignModel) {
            return response()->json([
                'error' => 'Campaign not found',
            ], 404);
        }

        // Get lists assigned to this campaign (bypass scope)
        $listIds = OrganizationScope::bypass(
            fn () => AutoDialerList::where('campaign_id', $campaign)->pluck('id')->toArray()
        );

        if (empty($listIds)) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'limit' => $limit, 'offset' => 0],
            ]);
        }

        // Get pending destinations (bypass scope)
        $destinations = OrganizationScope::bypass(function () use ($listIds, $campaignModel, $limit) {
            return AutoDialerDestination::whereIn('list_id', $listIds)
                ->where('status', DestinationStatus::PENDING)
                ->where('dial_attempts', '<', $campaignModel->max_dial_attempts)
                ->orderBy('created_at', 'asc')
                ->limit($limit)
                ->get();
        });

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

        $limit = $request->input('limit', 50);

        // Bypass organization scope and find campaign
        $campaignModel = OrganizationScope::bypass(
            fn () => AutoDialerCampaign::find($campaign)
        );

        if (! $campaignModel) {
            return response()->json([
                'error' => 'Campaign not found',
            ], 404);
        }

        // Get lists assigned to this campaign (bypass scope)
        $listIds = OrganizationScope::bypass(
            fn () => AutoDialerList::where('campaign_id', $campaign)->pluck('id')->toArray()
        );

        if (empty($listIds)) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'limit' => $limit, 'offset' => 0],
            ]);
        }

        // Retryable dispositions
        $retryableDispositions = ['busy', 'no-answer', 'cancelled'];

        // Get destinations ready for retry (bypass scope)
        $destinations = OrganizationScope::bypass(function () use ($listIds, $campaignModel, $limit, $retryableDispositions) {
            return AutoDialerDestination::whereIn('list_id', $listIds)
                ->whereIn('last_disposition', $retryableDispositions)
                ->where('dial_attempts', '<', $campaignModel->max_dial_attempts)
                ->where(function ($query) {
                    $query->whereNull('next_retry_at')
                        ->orWhere('next_retry_at', '<=', now());
                })
                ->orderBy('next_retry_at', 'asc')
                ->limit($limit)
                ->get();
        });

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
            'campaign_id' => ['required', 'integer'],
            'destination_id' => ['required', 'integer'],
            'phone_number' => ['required', 'string'],
            'worker_id' => ['required', 'string'],
            'initiated_at' => ['required', 'date'],
        ]);

        // Bypass organization scope for worker API
        $campaign = OrganizationScope::bypass(
            fn () => AutoDialerCampaign::find($validated['campaign_id'])
        );

        $destination = OrganizationScope::bypass(
            fn () => AutoDialerDestination::find($validated['destination_id'])
        );

        if (! $campaign || ! $destination) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'campaign_id' => ! $campaign ? ['The selected campaign id is invalid.'] : [],
                    'destination_id' => ! $destination ? ['The selected destination id is invalid.'] : [],
                ],
            ], 422);
        }

        // Create call session within scope bypass
        $session = OrganizationScope::bypass(function () use ($campaign, $destination, $validated) {
            return AutoDialerCallSession::create([
                'organization_id' => $campaign->organization_id,
                'campaign_id' => $campaign->id,
                'destination_id' => $destination->id,
                'session_token' => 'sess-'.uniqid(),
                'phone_number' => $validated['phone_number'],
                'worker_id' => $validated['worker_id'],
                'status' => 'initiated',
                'initiated_at' => $validated['initiated_at'],
            ]);
        });

        // Update destination status
        OrganizationScope::bypass(function () use ($destination) {
            $destination->update([
                'status' => DestinationStatus::DIALING,
                'dial_attempts' => $destination->dial_attempts + 1,
                'last_dialed_at' => now(),
            ]);
        });

        // Increment campaign pending calls counter
        OrganizationScope::bypass(fn () => $campaign->increment('pending_calls'));

        Log::info('DialerWorker: Call initiated', [
            'session_id' => $session->id,
            'campaign_id' => $campaign->id,
            'destination_id' => $destination->id,
            'worker_id' => $validated['worker_id'],
        ]);

        // Generate webhook callback URL for Cloudonix
        $webhookBaseUrl = config('app.webhook_base_url') ?? config('app.url');
        $callbackUrl = rtrim($webhookBaseUrl, '/').'/api/webhooks/cloudonix/call-status';

        return response()->json([
            'message' => 'Call initiated',
            'data' => [
                'session_id' => $session->id,
                'call_id' => $session->call_id ?? null,
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

        // Bypass organization scope to find session
        $sessionModel = OrganizationScope::bypass(
            fn () => AutoDialerCallSession::find($session)
        );

        if (! $sessionModel) {
            return response()->json([
                'error' => 'Session not found',
            ], 404);
        }

        $campaign = OrganizationScope::bypass(
            fn () => $sessionModel->campaign
        );

        // Update session
        OrganizationScope::bypass(function () use ($sessionModel, $validated) {
            $sessionModel->update([
                'status' => $validated['status'],
                'disposition' => $validated['disposition'],
                'duration' => $validated['duration'] ?? 0,
                'billsec' => $validated['billsec'] ?? 0,
                'recording_url' => $validated['recording_url'] ?? null,
                'completed_at' => $validated['completed_at'] ?? null,
            ]);
        });

        // Update destination
        $destination = OrganizationScope::bypass(
            fn () => $sessionModel->destination
        );

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
        } else {
            // Default to current status if disposition doesn't match expected values
            $destinationData['status'] = $destination->status;
        }

        OrganizationScope::bypass(function () use ($destination, $destinationData) {
            $destination->update($destinationData);
        });

        // Decrement pending calls
        $campaign->decrement('pending_calls');

        Log::info('DialerWorker: Call status updated', [
            'session_id' => $sessionModel->id,
            'status' => $validated['status'],
            'disposition' => $validated['disposition'],
        ]);

        return response()->json([
            'message' => 'Call status updated',
            'data' => [
                'session_id' => $sessionModel->id,
                'destination_status' => $destinationData['status']->value,
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

        // Bypass organization scope for worker API calls
        $sessionModel = OrganizationScope::bypass(function () use ($session): ?AutoDialerCallSession {
            return AutoDialerCallSession::with(['campaign', 'destination'])->find($session);
        });

        if (! $sessionModel) {
            return response()->json([
                'error' => 'Session not found',
            ], 404);
        }

        $session = $sessionModel;
        $campaign = $session->campaign;
        $destination = $session->destination;

        if (! $campaign || ! $destination) {
            return response()->json([
                'error' => 'Campaign or destination not found',
            ], 404);
        }

        // Perform updates within scope bypass and get destination status
        $destinationStatus = OrganizationScope::bypass(function () use ($session, $campaign, $destination, $validated): DestinationStatus {
            // Update session
            $session->update([
                'disposition' => $validated['disposition'],
                'duration' => $validated['duration'] ?? 0,
                'billsec' => $validated['billsec'] ?? 0,
                'completed_at' => now(),
            ]);

            // Build destination update data
            $destinationData = [
                'last_disposition' => $validated['disposition'],
                'duration' => $validated['duration'] ?? 0,
                'billsec' => $validated['billsec'] ?? 0,
            ];

            // Determine status based on disposition and retry settings
            $completedDispositions = ['answered', 'completed'];

            if (in_array($validated['disposition'], $completedDispositions, true)) {
                // Successful call
                $destinationData['status'] = DestinationStatus::COMPLETED;
                $session->update(['status' => 'completed']);
                $campaign->increment('completed_calls');
            } elseif ($validated['should_retry'] && $validated['next_retry_at']) {
                // Schedule for retry
                $destinationData['status'] = DestinationStatus::PENDING;
                $destinationData['next_retry_at'] = $validated['next_retry_at'];
                $session->update(['status' => 'failed']);

                Log::info('DialerWorker: Destination scheduled for retry', [
                    'session_id' => $session->id,
                    'destination_id' => $destination->id,
                    'attempt_number' => $validated['attempt_number'],
                    'next_retry_at' => $validated['next_retry_at'],
                ]);
            } else {
                // Permanent failure
                $destinationData['status'] = DestinationStatus::FAILED;
                $session->update(['status' => 'failed']);
                $campaign->increment('failed_calls');
            }

            $destination->update($destinationData);

            // Decrement pending calls if not already done
            if ($campaign->pending_calls > 0) {
                $campaign->decrement('pending_calls');
            }

            return $destinationData['status'];
        });

        Log::info('DialerWorker: Disposition set', [
            'session_id' => $session->id,
            'disposition' => $validated['disposition'],
            'should_retry' => $validated['should_retry'],
            'attempt_number' => $validated['attempt_number'],
        ]);

        return response()->json([
            'message' => 'Disposition set successfully',
            'data' => [
                'session_id' => $session->id,
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

        // Bypass organization scope to find campaign
        $campaignModel = OrganizationScope::bypass(
            fn () => AutoDialerCampaign::find($campaign)
        );

        if (! $campaignModel) {
            return response()->json([
                'error' => 'Campaign not found',
            ], 404);
        }

        OrganizationScope::bypass(function () use ($campaignModel) {
            $campaignModel->update([
                'status' => CampaignStatus::PAUSED,
            ]);
        });

        // Store pause info in cache
        Cache::put(
            "campaign_pause:{$campaign}",
            [
                'reason' => $validated['reason'],
                'paused_by' => $validated['paused_by'],
                'resume_at' => $validated['resume_at'] ?? null,
                'paused_at' => now()->toIso8601String(),
            ],
            now()->addHours(24)
        );

        Log::info('DialerWorker: Campaign paused', [
            'campaign_id' => $campaign,
            'reason' => $validated['reason'],
            'paused_by' => $validated['paused_by'],
        ]);

        return response()->json([
            'message' => 'Campaign paused',
            'data' => [
                'campaign_id' => $campaign,
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

        // Accept any array structure for flexibility
        $validated = $request->validate([
            'worker_id' => ['required', 'string'],
            'active_calls' => ['present', 'array'],
            'retry_queue' => ['present', 'array'],
            'campaign_states' => ['present', 'array'],
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

        // Count active campaigns (bypass scope)
        $activeCampaigns = OrganizationScope::bypass(
            fn () => AutoDialerCampaign::where('status', CampaignStatus::ACTIVE)->count()
        );

        // Count active call sessions (bypass scope)
        $activeCalls = OrganizationScope::bypass(
            fn () => AutoDialerCallSession::whereIn('status', ['initiated', 'ringing', 'answered'])->count()
        );

        // Count destinations in retry queue (approximate)
        $retryableDispositions = ['busy', 'no-answer', 'cancelled'];
        $queueDepth = OrganizationScope::bypass(
            fn () => AutoDialerDestination::whereIn('last_disposition', $retryableDispositions)
                ->where(function ($query) {
                    $query->whereNull('next_retry_at')
                        ->orWhere('next_retry_at', '<=', now());
                })
                ->count()
        );

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

    /**
     * Generate CXML for outbound call routing.
     *
     * This endpoint generates CXML that tells Cloudonix how to route an outbound call,
     * similar to how inbound calls are routed via the voice routing endpoint.
     *
     * Supported routing types:
     * - ai_assistant: Routes to AI Assistant (WebSocket or SIP)
     * - ring_group: Routes to a ring group
     * - ivr_menu: Routes to an IVR menu
     * - extension: Routes to a specific extension
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

        // Bypass organization scope for worker API
        $campaign = OrganizationScope::bypass(
            fn () => AutoDialerCampaign::with(['aiAssistant', 'aiLoadBalancer'])->find($validated['campaign_id'])
        );

        if (! $campaign) {
            return response()->json([
                'error' => 'Campaign not found',
            ], 404);
        }

        try {
            $cxml = $this->generateRoutingCxml($campaign, $validated);

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
            ]);
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
     * Generate CXML based on campaign routing configuration.
     *
     * @param  AutoDialerCampaign  $campaign  The campaign configuration
     * @param  array<string, mixed>  $params  Call parameters (session_id, phone_number, call_sid)
     * @return string The generated CXML
     *
     * @throws \InvalidArgumentException If routing configuration is invalid
     */
    private function generateRoutingCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $routingType = $campaign->routing_destination_type;

        return match ($routingType) {
            RoutingDestinationType::AI_ASSISTANT => $this->generateAiAssistantCxml($campaign, $params),
            RoutingDestinationType::AI_LOAD_BALANCER => $this->generateAiLoadBalancerCxml($campaign, $params),
            RoutingDestinationType::RING_GROUP => $this->generateRingGroupCxml($campaign, $params),
            RoutingDestinationType::IVR_MENU => $this->generateIvrMenuCxml($campaign, $params),
            RoutingDestinationType::EXTENSION => $this->generateExtensionCxml($campaign, $params),
            default => throw new \InvalidArgumentException("Unsupported routing type: {$routingType?->value}"),
        };
    }

    /**
     * Generate CXML for AI Assistant routing.
     *
     * Supports both WebSocket and SIP protocols.
     */
    private function generateAiAssistantCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $aiAssistant = $campaign->aiAssistant;

        if (! $aiAssistant) {
            throw new \InvalidArgumentException('AI Assistant not found for campaign');
        }

        $config = $aiAssistant->configuration ?? [];
        $protocol = $aiAssistant->protocol;
        $provider = $aiAssistant->provider;

        // Route based on protocol
        if ($protocol === 'websocket') {
            return $this->generateWebSocketCxml($aiAssistant, $config, $provider, $params);
        } else {
            return $this->generateSipCxml($aiAssistant, $config, $provider, $params);
        }
    }

    /**
     * Generate CXML for WebSocket-based AI Assistant.
     */
    private function generateWebSocketCxml(AiAssistant $aiAssistant, array $config, ?string $provider, array $params): string
    {
        if (! $provider) {
            throw new \InvalidArgumentException('AI Assistant provider not configured');
        }

        // Get provider registry to resolve URL template
        $providerRegistry = app(ProviderRegistry::class);
        $providerDef = $providerRegistry->getProvider($provider);

        if (! $providerDef || ! $providerDef->isWebSocketProvider()) {
            throw new \InvalidArgumentException('Invalid WebSocket provider configuration');
        }

        // Build WebSocket URL using the URL builder
        $urlBuilder = app(WebSocketUrlBuilder::class);
        $cloudonixParams = [
            'session' => $params['call_sid'],
            'from' => $params['phone_number'], // Outbound: destination is "from" in CXML context
            'to' => $campaign->caller_id ?? '', // Outbound: caller ID is "to" in CXML context
        ];

        $websocketUrl = $urlBuilder->buildUrl(
            $providerDef->urlTemplate,
            $config,
            $cloudonixParams
        );

        Log::debug('DialerWorker: Generated WebSocket URL for AI Assistant', [
            'ai_assistant_id' => $aiAssistant->id,
            'provider' => $provider,
            'websocket_url' => $websocketUrl,
        ]);

        // Generate CXML with Connect>Stream verb
        return CxmlBuilder::streamToWebSocket($websocketUrl);
    }

    /**
     * Generate CXML for SIP-based AI Assistant.
     */
    private function generateSipCxml(AiAssistant $aiAssistant, array $config, ?string $provider, array $params): string
    {
        // Check if AI Assistant has service URL (preferred for generic service URLs)
        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('ai_assistant_id', $aiAssistant->id)
            ->where('organization_id', $aiAssistant->organization_id)
            ->first();

        if ($extension && $extension->service_url) {
            Log::debug('DialerWorker: Using extension service URL for SIP routing', [
                'ai_assistant_id' => $aiAssistant->id,
                'extension_id' => $extension->id,
                'service_url' => $extension->service_url,
            ]);

            return CxmlBuilder::dialService(
                $extension->service_url,
                $extension->service_token,
                $extension->service_params ?? []
            );
        }

        // Fall back to legacy provider + phone number format
        $phoneNumber = $config['phone_number'] ?? null;

        if (! $provider || ! $phoneNumber) {
            throw new \InvalidArgumentException('SIP AI Assistant missing provider or phone number');
        }

        Log::debug('DialerWorker: Using provider format for SIP routing', [
            'ai_assistant_id' => $aiAssistant->id,
            'provider' => $provider,
            'phone_number' => $phoneNumber,
        ]);

        return CxmlBuilder::dialServiceProvider($provider, $phoneNumber);
    }

    /**
     * Generate CXML for AI Load Balancer routing.
     */
    private function generateAiLoadBalancerCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $loadBalancer = $campaign->aiLoadBalancer;

        if (! $loadBalancer) {
            throw new \InvalidArgumentException('AI Load Balancer not found for campaign');
        }

        // Get the active AI assistant from the load balancer
        $aiAssistant = $loadBalancer->getActiveAssistant();

        if (! $aiAssistant) {
            throw new \InvalidArgumentException('No active AI Assistant found in load balancer');
        }

        $config = $aiAssistant->configuration ?? [];
        $protocol = $aiAssistant->protocol;
        $provider = $aiAssistant->provider;

        if ($protocol === 'websocket') {
            return $this->generateWebSocketCxml($aiAssistant, $config, $provider, $params);
        } else {
            return $this->generateSipCxml($aiAssistant, $config, $provider, $params);
        }
    }

    /**
     * Generate CXML for Ring Group routing.
     */
    private function generateRingGroupCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $ringGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $ringGroup) {
            throw new \InvalidArgumentException('Ring group not found');
        }

        // Get members' SIP URIs
        $sipUris = [];
        foreach ($ringGroup->members as $member) {
            if ($member->extension && $member->extension->isActive()) {
                $sipUris[] = $member->extension->getSipUri();
            }
        }

        if (empty($sipUris)) {
            throw new \InvalidArgumentException('Ring group has no active members');
        }

        Log::debug('DialerWorker: Generated ring group CXML', [
            'ring_group_id' => $ringGroup->id,
            'member_count' => count($sipUris),
        ]);

        // Generate webhook callback URL for ring group fallback
        $webhookBaseUrl = config('app.webhook_base_url') ?? config('app.url');
        $actionUrl = rtrim($webhookBaseUrl, '/').'/api/voice/ring-group-callback?ring_group_id='.$ringGroup->id;

        return CxmlBuilder::dialRingGroup($sipUris, $campaign->dial_timeout ?? 30, $actionUrl);
    }

    /**
     * Generate CXML for IVR Menu routing.
     */
    private function generateIvrMenuCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $ivrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $ivrMenu) {
            throw new \InvalidArgumentException('IVR menu not found');
        }

        // Generate webhook URL for IVR input handling
        $webhookBaseUrl = config('app.webhook_base_url') ?? config('app.url');
        $actionUrl = rtrim($webhookBaseUrl, '/').'/api/voice/ivr-input?menu_id='.$ivrMenu->id;

        // Build the IVR prompt
        $nestedVerbs = '';
        if ($ivrMenu->greeting_audio_url) {
            $nestedVerbs .= CxmlBuilder::playXml($ivrMenu->greeting_audio_url);
        } elseif ($ivrMenu->greeting_text) {
            $nestedVerbs .= CxmlBuilder::sayXml($ivrMenu->greeting_text);
        }

        Log::debug('DialerWorker: Generated IVR menu CXML', [
            'ivr_menu_id' => $ivrMenu->id,
            'has_audio' => (bool) $ivrMenu->greeting_audio_url,
        ]);

        return CxmlBuilder::gather(
            $nestedVerbs,
            $actionUrl,
            $ivrMenu->timeout ?? 5,
            $ivrMenu->finish_on_key ?? '#',
            $ivrMenu->min_digits ?? 1,
            $ivrMenu->max_digits ?? 1
        );
    }

    /**
     * Generate CXML for Extension routing.
     */
    private function generateExtensionCxml(AutoDialerCampaign $campaign, array $params): string
    {
        $extension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $campaign->routing_destination_id)
            ->where('organization_id', $campaign->organization_id)
            ->first();

        if (! $extension) {
            throw new \InvalidArgumentException('Extension not found');
        }

        Log::debug('DialerWorker: Generated extension CXML', [
            'extension_id' => $extension->id,
            'extension_number' => $extension->extension_number,
        ]);

        return CxmlBuilder::dialExtension($extension->getSipUri(), $campaign->dial_timeout ?? 30);
    }
}
