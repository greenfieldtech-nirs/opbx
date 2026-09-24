<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Enums\RingGroupFallbackAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\QueueCallbackRequest;
use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use App\Models\CallQueue;
use App\Models\Extension;
use App\Models\IvrMenu;
use App\Models\QueueCall;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\AcdWorkerClient;
use App\Services\CallQueue\ExtensionPresenceTracker;
use App\Services\CallQueue\QueueCallLifecycleService;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\Strategies\QueueRoutingStrategy;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Queue poll endpoint: called by Cloudonix after each hold-music play.
 *
 * Asks the acd-worker for a decision (wait / dial / overflow) and returns
 * the next CXML for the waiting caller.
 */
class QueuePollController extends Controller
{
    public function handle(QueueCallbackRequest $request): Response
    {
        $context = $request->queueContext();

        if ($context === null) {
            Log::warning('QueuePollController: Missing queue context in callback', [
                'call_sid' => $request->input('CallSid'),
                'has_session_data' => $request->filled('session_data'),
                'has_sessiondata' => $request->has('SessionData'),
            ]);

            return response(CxmlBuilder::unavailable('Queue system error'), 200, ['Content-Type' => 'application/xml']);
        }

        $callQueue = CallQueue::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $context['call_queue_id'])
            ->when($context['organization_id'], fn ($q, $orgId) => $q->where('organization_id', $orgId))
            ->first();

        if (! $callQueue) {
            return response(CxmlBuilder::unavailable('Call queue not found'), 200, ['Content-Type' => 'application/xml']);
        }

        $queueCall = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->where('call_id', $context['call_id'])
            ->whereNull('disposition')
            ->first();

        // No pending queue call: already answered/abandoned/overflowed, or the
        // strategy never saw this call (unknown state) — end gracefully.
        if (! $queueCall) {
            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        // Stale-document guard: with immediate connect, the pre-switch hold
        // document is still in flight (the switch does not abort it) and its
        // Redirect fires this poll while the offer is in flight - or after
        // the agent already answered. In both cases the response would tear
        // down the live <Dial>/bridge (customer-visible congestion), so we
        // re-serve the SAME <Dial> to the same agent and never ask the worker
        // for a new offer here.
        $offerAgentId = app(QueueCallLifecycleService::class)->dialOfferAgentId($queueCall->call_id);
        $dialResult = \Illuminate\Support\Facades\Redis::get("acd:dialresult:{$queueCall->call_id}");
        $offerInFlight = $offerAgentId !== null && $dialResult === null;
        $bridged = $queueCall->answered_at !== null;

        if ($bridged || $offerInFlight) {
            $agent = \App\Models\User::withoutGlobalScope(OrganizationScope::class)
                ->with(['extension' => fn ($q) => $q->withoutGlobalScope(OrganizationScope::class)])
                ->find($offerAgentId);

            if ($agent?->extension) {
                Log::info('QueuePollController: Stale poll, re-serving same dial', [
                    'call_queue_id' => $callQueue->id,
                    'call_id' => $queueCall->call_id,
                    'bridged' => $bridged,
                    'agent_user_id' => $offerAgentId,
                ]);

                $dialResponse = app(\App\Services\CallQueue\QueueDialOfferService::class)
                    ->buildDialResponse($request, $callQueue, $queueCall, [[
                        'userId' => (string) $agent->id,
                        'extensionNumber' => $agent->extension->extension_number,
                    ]], respectPresence: false);

                if ($dialResponse !== null) {
                    return $dialResponse;
                }
            }
        }

        $result = app(AcdWorkerClient::class)->poll(
            $callQueue->organization_id,
            $callQueue->id,
            $context['call_id'],
            app(\App\Services\CallQueue\QueueDialOfferService::class)->roster($callQueue),
            $callQueue->strategy->value,
            $callQueue->max_wait_seconds
        );

        // Worker unreachable: keep holding; the worker re-enqueues on recovery.
        if ($result === null) {
            return app(QueueRoutingStrategy::class)->holdResponse($request, $callQueue, $context['call_id']);
        }

        // Reap ghost worker entries (ended calls never dequeued) so a stale
        // head cannot block the queue, then re-check the decision once.
        if (($result['action'] ?? 'wait') === 'wait') {
            $dialOffer = app(\App\Services\CallQueue\QueueDialOfferService::class);
            $roster = $dialOffer->roster($callQueue);
            $waiting = app(AcdWorkerClient::class)->live($callQueue->organization_id, $callQueue->id, $roster);

            app(\App\Services\CallQueue\QueueCallLifecycleService::class)
                ->reconcileStaleCalls($callQueue, collect($waiting['waiting'] ?? [])->pluck('callId')->all());

            $result = app(AcdWorkerClient::class)->poll(
                $callQueue->organization_id,
                $callQueue->id,
                $context['call_id'],
                $roster,
                $callQueue->strategy->value,
                $callQueue->max_wait_seconds
            ) ?? $result;
        }

        // Per-caller max-wait enforcement (source of truth: queue_calls.entered_at).
        // The worker only overflows the queue head when the head itself is polled —
        // a dead/abandoned caller rotting at the head would otherwise block the
        // queue forever and callers behind it would never fall back.
        if (($result['action'] ?? 'wait') === 'wait'
            && $this->maxWaitExceeded($callQueue, $queueCall)) {
            Log::info('QueuePollController: Caller exceeded max wait, overflowing', [
                'call_queue_id' => $callQueue->id,
                'call_id' => $queueCall->call_id,
                'entered_at' => $queueCall->entered_at->toIso8601String(),
                'max_wait_seconds' => $callQueue->max_wait_seconds,
            ]);

            return $this->handleOverflow($request, $callQueue, $queueCall);
        }

        return match ($result['action'] ?? 'wait') {
            'dial' => $this->handleDial($request, $callQueue, $queueCall, $result['agents'] ?? []),
            'overflow' => $this->handleOverflow($request, $callQueue, $queueCall),
            default => $this->holdOrAnnounce($request, $callQueue, $queueCall, (int) ($result['position'] ?? 1)),
        };
    }

    /**
     * Has this caller been waiting longer than the queue's max wait?
     */
    private function maxWaitExceeded(CallQueue $callQueue, QueueCall $queueCall): bool
    {
        $waitedSeconds = max(0, (int) $queueCall->entered_at->diffInSeconds(now()));

        return $waitedSeconds >= $callQueue->max_wait_seconds;
    }

    /**
     * Wait decision: render the hold CXML, announcing the caller's position
     * when enabled and the announce interval has elapsed since the last one.
     */
    private function holdOrAnnounce(QueueCallbackRequest $request, CallQueue $callQueue, QueueCall $queueCall, int $position): Response
    {
        $announcePosition = null;

        if ($callQueue->announce_position) {
            $waitedSeconds = max(0, (int) $queueCall->entered_at->diffInSeconds(now()));
            $marker = \Illuminate\Support\Facades\Redis::get("acd:announce:{$queueCall->call_id}");

            // Announce on the first poll of the call, then every interval.
            // Without the null-marker branch, a caller whose announce interval
            // exceeds max_wait would overflow without ever hearing a position.
            $neverAnnounced = $marker === null || $marker === false;
            $lastAnnounced = (int) $marker;

            if ($neverAnnounced || $waitedSeconds - $lastAnnounced >= $callQueue->announce_position_timeout) {
                $announcePosition = $position;
                \Illuminate\Support\Facades\Redis::setex(
                    "acd:announce:{$queueCall->call_id}",
                    $callQueue->max_wait_seconds + 300,
                    (string) $waitedSeconds
                );
            }
        }

        return app(QueueRoutingStrategy::class)->holdResponse(
            $request,
            $callQueue,
            $queueCall->call_id,
            $announcePosition
        );
    }

    /**
     * @param  array<int, array{userId: string, extensionNumber: string}>  $agents
     */
    private function handleDial(QueueCallbackRequest $request, CallQueue $callQueue, QueueCall $queueCall, array $agents): Response
    {
        $dialResponse = app(\App\Services\CallQueue\QueueDialOfferService::class)
            ->buildDialResponse($request, $callQueue, $queueCall, $agents);

        if ($dialResponse !== null) {
            return $dialResponse;
        }

        // All offered agents are busy on other calls: keep holding.
        return app(QueueRoutingStrategy::class)->holdResponse($request, $callQueue, $queueCall->call_id);
    }

    private function handleOverflow(QueueCallbackRequest $request, CallQueue $callQueue, QueueCall $queueCall): Response
    {
        Log::info('QueuePollController: Call overflowed (max wait exceeded)', [
            'call_queue_id' => $callQueue->id,
            'call_queue_name' => $callQueue->name,
            'call_id' => $queueCall->call_id,
        ]);

        app(QueueCallLifecycleService::class)->markOverflowed($queueCall);

        $response = $this->handleFallback($callQueue, $request);

        // Reassure the caller before routing (not for hangup — the hangup
        // fallback speaks its own goodbye).
        if ($callQueue->fallback_action !== RingGroupFallbackAction::HANGUP) {
            $response = $this->prependSay(
                $response,
                "We're sorry, but no one is available to take your call. "
                    .'Please hold while we redirect your call.',
                $callQueue->announce_position_language
            );
        }

        return $response;
    }

    /**
     * Insert a Say verb at the top of a CXML response (spoken in the given
     * TTS language when provided).
     */
    private function prependSay(Response $response, string $text, ?string $language): Response
    {
        $document = new \DOMDocument;
        if (! $document->loadXML((string) $response->getContent())) {
            return $response;
        }

        $say = $document->createElement('Say', htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        if ($language) {
            $say->setAttribute('language', $language);
        }
        $document->documentElement->insertBefore($say, $document->documentElement->firstChild);

        return response($document->saveXML(), 200, ['Content-Type' => 'application/xml']);
    }

    /**
     * Route an overflowed call to the queue's fallback destination.
     * Mirrors the ring group fallback delegation pattern.
     */
    private function handleFallback(CallQueue $callQueue, QueueCallbackRequest $request): Response
    {
        $fallbackAction = $callQueue->fallback_action;

        Log::info('QueuePollController: Executing fallback action', [
            'call_queue_id' => $callQueue->id,
            'fallback_action' => $fallbackAction->value,
        ]);

        return match ($fallbackAction) {
            RingGroupFallbackAction::EXTENSION => $this->fallbackToExtension($callQueue, $request),
            RingGroupFallbackAction::RING_GROUP => $this->fallbackToRingGroup($callQueue, $request),
            RingGroupFallbackAction::IVR_MENU => $this->fallbackToIvrMenu($callQueue, $request),
            RingGroupFallbackAction::AI_ASSISTANT => $this->fallbackToAiAssistant($callQueue, $request),
            RingGroupFallbackAction::AI_LOAD_BALANCER => $this->fallbackToAiLoadBalancer($callQueue, $request),
            default => response(CxmlBuilder::unavailable('No agents available. Goodbye.'), 200, ['Content-Type' => 'application/xml']),
        };
    }

    private function fallbackToExtension(CallQueue $callQueue, QueueCallbackRequest $request): Response
    {
        $fallbackExtension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $callQueue->fallback_extension_id)
            ->where('organization_id', $callQueue->organization_id)
            ->first();

        if (! $fallbackExtension || ! $fallbackExtension->isActive()) {
            return response(CxmlBuilder::unavailable('No agents available. Goodbye.'), 200, ['Content-Type' => 'application/xml']);
        }

        return app(VoiceRoutingManager::class)->executeStrategy(
            $fallbackExtension->type,
            $request,
            new \App\Models\DidNumber,
            ['extension' => $fallbackExtension]
        );
    }

    private function fallbackToRingGroup(CallQueue $callQueue, QueueCallbackRequest $request): Response
    {
        $fallbackRingGroup = RingGroup::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $callQueue->fallback_ring_group_id)
            ->where('organization_id', $callQueue->organization_id)
            ->first();

        if (! $fallbackRingGroup || ! $fallbackRingGroup->isActive()) {
            return response(CxmlBuilder::unavailable('No agents available. Goodbye.'), 200, ['Content-Type' => 'application/xml']);
        }

        return app(VoiceRoutingManager::class)->executeStrategy(
            \App\Enums\ExtensionType::RING_GROUP,
            $request,
            new \App\Models\DidNumber,
            ['ring_group' => $fallbackRingGroup]
        );
    }

    private function fallbackToIvrMenu(CallQueue $callQueue, QueueCallbackRequest $request): Response
    {
        $fallbackIvrMenu = IvrMenu::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $callQueue->fallback_ivr_menu_id)
            ->where('organization_id', $callQueue->organization_id)
            ->first();

        if (! $fallbackIvrMenu || ! $fallbackIvrMenu->isActive()) {
            return response(CxmlBuilder::unavailable('No agents available. Goodbye.'), 200, ['Content-Type' => 'application/xml']);
        }

        return app(VoiceRoutingManager::class)->executeStrategy(
            \App\Enums\ExtensionType::IVR,
            $request,
            new \App\Models\DidNumber,
            ['ivr_menu' => $fallbackIvrMenu]
        );
    }

    private function fallbackToAiAssistant(CallQueue $callQueue, QueueCallbackRequest $request): Response
    {
        $fallbackAiAssistant = AiAssistant::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $callQueue->fallback_ai_assistant_id)
            ->where('organization_id', $callQueue->organization_id)
            ->first();

        if (! $fallbackAiAssistant || ! $fallbackAiAssistant->isActive()) {
            return response(CxmlBuilder::unavailable('No agents available. Goodbye.'), 200, ['Content-Type' => 'application/xml']);
        }

        $fallbackExtension = Extension::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $callQueue->organization_id)
            ->where('type', \App\Enums\ExtensionType::AI_ASSISTANT)
            ->get()
            ->first(fn (Extension $ext) => ($ext->configuration['ai_assistant_id'] ?? null) === $fallbackAiAssistant->id);

        if (! $fallbackExtension) {
            return response(CxmlBuilder::unavailable('No agents available. Goodbye.'), 200, ['Content-Type' => 'application/xml']);
        }

        return app(VoiceRoutingManager::class)->executeStrategy(
            \App\Enums\ExtensionType::AI_ASSISTANT,
            $request,
            new \App\Models\DidNumber,
            ['extension' => $fallbackExtension]
        );
    }

    private function fallbackToAiLoadBalancer(CallQueue $callQueue, QueueCallbackRequest $request): Response
    {
        $aiLoadBalancer = AiAssistantLoadBalancer::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $callQueue->fallback_ai_load_balancer_id)
            ->where('organization_id', $callQueue->organization_id)
            ->where('status', 'active')
            ->first();

        if (! $aiLoadBalancer) {
            return response(CxmlBuilder::unavailable('No agents available. Goodbye.'), 200, ['Content-Type' => 'application/xml']);
        }

        return app(VoiceRoutingManager::class)->executeStrategy(
            \App\Enums\ExtensionType::AI_LOAD_BALANCER,
            $request,
            new \App\Models\DidNumber,
            ['ai_load_balancer' => $aiLoadBalancer]
        );
    }

}
