<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Enums\AlbsStatus;
use App\Enums\RingGroupFallbackAction;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\AiAssistantLoadBalancerMember;
use App\Services\AiAssistant\ProviderRegistry;
use App\Services\AiAssistant\WebSocketUrlBuilder;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Controller for handling ALB Follow Through callbacks.
 *
 * When a call to an AI Assistant fails (busy, no-answer, etc.),
 * this controller selects the next available AI Assistant and redirects
 * the call accordingly.
 */
class AlbsFollowThroughController extends Controller
{
    private const TENTATIVE_ASSISTANTS_KEY = 'albs:tried:';

    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly WebSocketUrlBuilder $urlBuilder
    ) {}

    /**
     * Handle the follow-through callback from Cloudonix.
     *
     * This endpoint is called when a call to an AI Assistant fails.
     * It selects the next available assistant and returns CXML to redirect.
     */
    public function handle(Request $request): Response
    {
        $requestId = $this->getRequestId();

        // Log all incoming request data for debugging
        Log::info('ALBS Follow Through: Raw request received', [
            'request_id' => $requestId,
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'all_inputs' => $request->all(),
            'query_string' => $request->getQueryString(),
            'headers' => [
                'X-Cx-Session' => $request->header('X-Cx-Session'),
                'Authorization' => $request->header('Authorization') ? 'present' : 'missing',
            ],
        ]);

        // Merge query string parameters into request for easier access
        $queryParams = $request->query();
        if (! empty($queryParams)) {
            $request->merge($queryParams);
        }

        // Try multiple sources for CallSid
        $callSid = $request->input('CallSid')
            ?? $request->header('X-Cx-Session')
            ?? $request->input('SessionData.callSid')
            ?? $request->input('Session')
            ?? $request->input('session');

        // Get albs_id from query string or body
        $albsId = $request->query('albs_id') ?? $request->input('albs_id');

        // Get current_assistant_id from query string or body
        $failedAssistantId = $request->query('current_assistant_id') ?? $request->input('current_assistant_id');

        // Try multiple sources for status
        // Cloudonix sends 'DialCallStatus' in action callbacks, 'CallStatus' in initial requests
        $status = $request->input('DialCallStatus')
            ?? $request->input('CallStatus')
            ?? $request->input('status');

        // Log what we found
        Log::info('ALBS Follow Through: Parsed parameters', [
            'request_id' => $requestId,
            'call_sid' => $callSid,
            'albs_id' => $albsId,
            'current_assistant_id' => $failedAssistantId,
            'status' => $status,
            'call_status_raw' => $request->input('CallStatus'),
            'dial_call_status_raw' => $request->input('DialCallStatus'),
        ]);

        if (! $callSid || ! $albsId || ! $failedAssistantId) {
            Log::warning('ALBS Follow Through: Missing required parameters', [
                'request_id' => $requestId,
                'call_sid' => $callSid,
                'albs_id' => $albsId,
                'current_assistant_id' => $failedAssistantId,
            ]);

            return $this->errorResponse('Missing required parameters');
        }

        // Check if this status should trigger follow-through
        // Include 'unknown' as it often indicates a failure to connect (busy, no-answer, etc.)
        $shouldFollowThrough = in_array($status, ['busy', 'no-answer', 'failed', 'canceled', 'unknown']);

        Log::info('ALBS Follow Through: Checking status for follow-through', [
            'request_id' => $requestId,
            'status' => $status,
            'should_follow_through' => $shouldFollowThrough,
        ]);

        if (! $shouldFollowThrough) {
            Log::info('ALBS Follow Through: Status does not require follow-through', [
                'request_id' => $requestId,
                'call_sid' => $callSid,
                'status' => $status,
            ]);

            // Return empty response to let call continue normally
            return response('', 204);
        }

        // Load the AI Load Balancer
        $albs = AiAssistantLoadBalancer::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
            ->with(['members' => function ($query) {
                $query->where('status', 'active')
                    ->whereHas('aiAssistant', function ($q) {
                        $q->withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                            ->where('status', UserStatus::ACTIVE->value);
                    })
                    ->with(['aiAssistant' => function ($q) {
                        $q->withoutGlobalScope(\App\Scopes\OrganizationScope::class);
                    }]);
            }])
            ->where('id', $albsId)
            ->first();

        if (! $albs || $albs->status->value !== AlbsStatus::ACTIVE->value) {
            Log::warning('ALBS Follow Through: ALB not found or inactive', [
                'request_id' => $requestId,
                'albs_id' => $albsId,
                'albs_found' => $albs !== null,
                'albs_status' => $albs?->status?->value,
            ]);

            return $this->errorResponse('Load balancer not available');
        }

        // Check if follow_through is enabled on the ALB
        Log::info('ALBS Follow Through: Checking follow-through setting', [
            'request_id' => $requestId,
            'albs_id' => $albsId,
            'follow_through_enabled' => $albs->follow_through,
            'follow_through_raw' => $albs->getRawOriginal('follow_through'),
        ]);

        if (! $albs->follow_through) {
            Log::info('ALBS Follow Through: Follow-through disabled, executing fallback', [
                'request_id' => $requestId,
                'call_sid' => $callSid,
                'albs_id' => $albsId,
                'failed_assistant_id' => $failedAssistantId,
            ]);

            // Follow-through is disabled, execute fallback action immediately
            return $this->executeFallback($albs, $request);
        }

        // Follow-through is enabled - try next member

        // Get already-tried assistants
        $triedAssistantIds = $this->getTriedAssistants($callSid);

        Log::info('ALBS Follow Through: Getting tried assistants', [
            'request_id' => $requestId,
            'call_sid' => $callSid,
            'already_tried' => $triedAssistantIds,
        ]);

        // Add failed assistant to tried list
        $triedAssistantIds[] = (int) $failedAssistantId;

        Log::info('ALBS Follow Through: Selecting next assistant', [
            'request_id' => $requestId,
            'albs_id' => $albsId,
            'strategy' => $albs->strategy->value,
            'tried_ids' => $triedAssistantIds,
            'total_members' => $albs->members->count(),
        ]);

        // Select next assistant
        $nextAssistant = $this->selectNextAssistant($albs, $triedAssistantIds);

        if (! $nextAssistant) {
            Log::info('ALBS Follow Through: No more assistants to try', [
                'request_id' => $requestId,
                'albs_id' => $albsId,
                'tried_count' => count($triedAssistantIds),
            ]);

            // All assistants tried, execute original fallback
            return $this->executeFallback($albs, $request);
        }

        // Store updated tried list
        $this->setTriedAssistants($callSid, $triedAssistantIds);

        Log::info('ALBS Follow Through: Routing to next assistant', [
            'request_id' => $requestId,
            'call_sid' => $callSid,
            'albs_id' => $albsId,
            'next_assistant_id' => $nextAssistant->id,
            'next_assistant_name' => $nextAssistant->name,
            'tried_count' => count($triedAssistantIds),
        ]);

        // Generate CXML to route to next assistant
        return $this->routeToAssistant($nextAssistant, $albs, $request);
    }

    /**
     * Get list of already-tried assistant IDs for a call.
     */
    private function getTriedAssistants(string $callSid): array
    {
        $key = self::TENTATIVE_ASSISTANTS_KEY.$callSid;
        $data = Cache::get($key);

        if ($data) {
            return json_decode($data, true) ?? [];
        }

        return [];
    }

    /**
     * Store list of tried assistant IDs for a call.
     */
    private function setTriedAssistants(string $callSid, array $assistantIds): void
    {
        $key = self::TENTATIVE_ASSISTANTS_KEY.$callSid;
        Cache::put($key, json_encode($assistantIds), self::CACHE_TTL_SECONDS);
    }

    /**
     * Clear tried assistants cache for a call.
     */
    private function clearTriedAssistants(string $callSid): void
    {
        $key = self::TENTATIVE_ASSISTANTS_KEY.$callSid;
        Cache::forget($key);
    }

    /**
     * Select the next available AI Assistant based on strategy.
     */
    private function selectNextAssistant(AiAssistantLoadBalancer $albs, array $triedIds): ?AiAssistant
    {
        $availableMembers = $albs->members->reject(function (AiAssistantLoadBalancerMember $member) use ($triedIds) {
            return in_array($member->ai_assistant_id, $triedIds);
        });

        if ($availableMembers->isEmpty()) {
            return null;
        }

        return match ($albs->strategy->value) {
            'priority' => $this->selectByPriority($availableMembers),
            'percentage' => $this->selectByPercentage($availableMembers),
            'round_robin' => $this->selectByRoundRobin($availableMembers, $albs->id),
            default => $this->selectByPriority($availableMembers),
        };
    }

    /**
     * Select assistant with highest priority (lowest number).
     */
    private function selectByPriority($members): ?AiAssistant
    {
        $selected = $members->sortBy('priority')->first();

        return $selected?->aiAssistant;
    }

    /**
     * Select assistant based on percentage weights.
     */
    private function selectByPercentage($members): ?AiAssistant
    {
        $totalWeight = $members->sum('weight');

        if ($totalWeight <= 0) {
            return $members->first()?->aiAssistant;
        }

        $random = mt_rand(1, $totalWeight);
        $cumulative = 0;

        foreach ($members->sortBy('position') as $member) {
            $cumulative += $member->weight;

            if ($random <= $cumulative) {
                return $member->aiAssistant;
            }
        }

        return $members->first()?->aiAssistant;
    }

    /**
     * Select assistant using round robin across available members.
     */
    private function selectByRoundRobin($members, int $albsId): ?AiAssistant
    {
        $key = 'albs:rr:'.$albsId;
        $counter = Cache::increment($key);
        $index = ($counter - 1) % $members->count();

        $sorted = $members->sortBy('position');
        $member = $sorted->values()->get($index);

        return $member?->aiAssistant;
    }

    /**
     * Generate CXML to route to an AI Assistant.
     */
    private function routeToAssistant(AiAssistant $aiAssistant, AiAssistantLoadBalancer $albs, Request $request): Response
    {
        $config = $aiAssistant->configuration ?? [];
        $protocol = $aiAssistant->protocol;
        $provider = $aiAssistant->provider;
        $callSid = $request->input('CallSid');

        // Build follow-through callback URL
        $callbackUrl = $this->buildCallbackUrl($albs->id, $aiAssistant->id, $request);

        if ($protocol === 'websocket') {
            return $this->routeWebSocket($aiAssistant, $config, $provider, $callbackUrl, $request);
        }

        return $this->routeSip($aiAssistant, $config, $provider, $callbackUrl, $request);
    }

    /**
     * Build the follow-through callback URL.
     *
     * Note: Cloudonix sends the dial status in the request body as 'DialCallStatus'
     * when calling the action URL. It does NOT replace {STATUS} placeholders.
     *
     * Uses the organization's configured webhook_base_url, or falls back to the
     * current request's scheme and host.
     */
    private function buildCallbackUrl(int $albsId, int $currentAssistantId, Request $request): string
    {
        // Get organization ID from request (set by middleware)
        $organizationId = $request->input('_organization_id');

        // Look up organization's Cloudonix settings
        $cloudonixSettings = \App\Models\CloudonixSettings::where('organization_id', $organizationId)->first();

        // Use configured webhook base URL, or fall back to request host
        $baseUrl = $cloudonixSettings && $cloudonixSettings->webhook_base_url
            ? rtrim($cloudonixSettings->webhook_base_url, '/')
            : $request->getSchemeAndHttpHost();

        // Build the relative URL using the named route
        $relativeUrl = route('voice.albs-follow-through', [
            'albs_id' => $albsId,
            'current_assistant_id' => $currentAssistantId,
        ], false);

        return $baseUrl.$relativeUrl;
    }

    /**
     * Route to WebSocket-based AI Assistant.
     */
    private function routeWebSocket(AiAssistant $aiAssistant, array $config, ?string $provider, string $callbackUrl, Request $request): Response
    {
        if (! $provider) {
            Log::error('ALBS Follow Through: WebSocket assistant missing provider', [
                'ai_assistant_id' => $aiAssistant->id,
            ]);

            return $this->errorResponse('AI Assistant provider not configured');
        }

        $providerDef = $this->providerRegistry->getProvider($provider);

        if (! $providerDef || ! $providerDef->isWebSocketProvider()) {
            Log::error('ALBS Follow Through: Invalid WebSocket provider', [
                'ai_assistant_id' => $aiAssistant->id,
                'provider' => $provider,
            ]);

            return $this->errorResponse('Invalid AI Assistant provider configuration');
        }

        $cloudonixParams = [
            'session' => $request->input('CallSid', ''),
            'from' => $request->input('From', ''),
            'to' => $request->input('To', ''),
        ];

        try {
            $websocketUrl = $this->urlBuilder->buildUrl(
                $providerDef->urlTemplate,
                $config,
                $cloudonixParams
            );

            Log::info('ALBS Follow Through: Routing to WebSocket assistant', [
                'ai_assistant_id' => $aiAssistant->id,
                'provider' => $provider,
                'call_sid' => $cloudonixParams['session'],
            ]);

            // Using action parameter on Connect verb for callback
            $builder = CxmlBuilder::streamToWebSocketWithAction($websocketUrl, $callbackUrl);

            return response($builder, 200, ['Content-Type' => 'text/xml']);
        } catch (\InvalidArgumentException $e) {
            Log::error('ALBS Follow Through: Failed to build WebSocket URL', [
                'ai_assistant_id' => $aiAssistant->id,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('AI Assistant configuration error');
        }
    }

    /**
     * Route to SIP-based AI Assistant.
     */
    private function routeSip(AiAssistant $aiAssistant, array $config, ?string $provider, string $callbackUrl, Request $request): Response
    {
        $phoneNumber = $config['phone_number'] ?? null;

        if (! $provider || ! $phoneNumber) {
            Log::error('ALBS Follow Through: SIP assistant missing provider or phone number', [
                'ai_assistant_id' => $aiAssistant->id,
            ]);

            return $this->errorResponse('AI Assistant configuration incomplete');
        }

        Log::info('ALBS Follow Through: Routing to SIP assistant', [
            'ai_assistant_id' => $aiAssistant->id,
            'provider' => $provider,
            'phone_number' => $phoneNumber,
        ]);

        $builder = CxmlBuilder::dialServiceProviderWithAction($provider, $phoneNumber, $callbackUrl);

        return response($builder, 200, ['Content-Type' => 'text/xml']);
    }

    /**
     * Execute the original fallback action when all assistants fail.
     */
    private function executeFallback(AiAssistantLoadBalancer $albs, Request $request): Response
    {
        $fallbackAction = $albs->fallback_action;

        Log::info('ALBS Follow Through: Executing fallback', [
            'albs_id' => $albs->id,
            'fallback_action' => $fallbackAction->value,
        ]);

        return match ($fallbackAction) {
            RingGroupFallbackAction::HANGUP => $this->hangupResponse(),
            RingGroupFallbackAction::EXTENSION => $this->routeToExtension($albs, $request),
            RingGroupFallbackAction::RING_GROUP => $this->routeToRingGroup($albs, $request),
            RingGroupFallbackAction::IVR_MENU => $this->routeToIvrMenu($albs, $request),
            RingGroupFallbackAction::AI_ASSISTANT => $this->routeToAiAssistant($albs, $request),
            default => $this->hangupResponse(),
        };
    }

    /**
     * Return hangup CXML.
     */
    private function hangupResponse(): Response
    {
        return response(
            CxmlBuilder::unavailable('All AI assistants are unavailable. Goodbye.'),
            200,
            ['Content-Type' => 'text/xml']
        );
    }

    /**
     * Route to extension.
     */
    private function routeToExtension(AiAssistantLoadBalancer $albs, Request $request): Response
    {
        // This would need extension lookup - simplified for now
        return $this->hangupResponse();
    }

    /**
     * Route to ring group.
     */
    private function routeToRingGroup(AiAssistantLoadBalancer $albs, Request $request): Response
    {
        // This would need ring group lookup - simplified for now
        return $this->hangupResponse();
    }

    /**
     * Route to IVR menu.
     */
    private function routeToIvrMenu(AiAssistantLoadBalancer $albs, Request $request): Response
    {
        // This would need IVR lookup - simplified for now
        return $this->hangupResponse();
    }

    /**
     * Route to AI Assistant.
     */
    private function routeToAiAssistant(AiAssistantLoadBalancer $albs, Request $request): Response
    {
        // This would need AI Assistant lookup - simplified for now
        return $this->hangupResponse();
    }

    /**
     * Return error CXML response.
     */
    private function errorResponse(string $message): Response
    {
        return response(
            CxmlBuilder::unavailable($message),
            200,
            ['Content-Type' => 'text/xml']
        );
    }

    /**
     * Get unique request ID for logging.
     */
    private function getRequestId(): string
    {
        return uniqid('albs_ft_', true);
    }
}
