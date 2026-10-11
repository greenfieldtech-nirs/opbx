<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\QueueCallbackRequest;
use App\Models\CallQueue;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\AcdWorkerClient;
use App\Services\CallQueue\QueueCallLifecycleService;
use App\Services\CallQueue\QueueDialOfferService;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\Strategies\QueueRoutingStrategy;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Dial callback: Cloudonix reports the result of the agent <Dial>.
 *
 * busy/no-answer/failed → the agent did not take the call: release the agent
 * back to the pool (dial_failed event) and put the caller back on hold.
 * answered/completed → the bridge ended; call finalization is driven by the
 * CDR webhook, so we only hang up here.
 */
class QueueDialCallbackController extends Controller
{
    public function handle(QueueCallbackRequest $request): Response
    {
        $context = $request->queueContext();

        if ($context === null) {
            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        $callStatus = (string) $request->input('CallStatus', 'completed');

        // The dial result is the authoritative bridge outcome, used by the CDR
        // finalization to distinguish a real agent bridge from a spurious
        // teardown 'answer' session update.
        Log::info('QueueDialCallbackController: dial result', [
            'session_token' => $request->input('Session') ?? $request->input('token'),
            'call_queue_id' => $context['call_queue_id'],
            'call_id' => $context['call_id'],
            'call_status' => $callStatus,
        ]);

        // Answered guard: with multiple <Dial> targets (ring-all), the action
        // callback can report a LOSING leg's outcome (e.g. an unregistered
        // target fails) even though a real agent bridged and the call has
        // ended. A failure status after the bridge must not trigger a re-offer
        // (redial loop, origin never terminates, congestion) - record the
        // bridge and end the call cleanly.
        $queueCall = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $context['organization_id'])
            ->where('call_queue_id', $context['call_queue_id'])
            ->where('call_id', $context['call_id'])
            ->whereNull('disposition')
            ->first();

        if ($queueCall?->answered_at !== null) {
            Log::info('QueueDialCallbackController: dial completed after bridge, ignoring leg status', [
                'call_queue_id' => $context['call_queue_id'],
                'call_id' => $context['call_id'],
                'call_status' => $callStatus,
            ]);

            app(QueueCallLifecycleService::class)->recordDialResult($context['call_id'], 'completed');

            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        // The queue must belong to the authenticated org before any state is touched.
        $callQueue = CallQueue::withoutGlobalScope(OrganizationScope::class)
            ->where('id', $context['call_queue_id'])
            ->where('organization_id', $context['organization_id'])
            ->first();

        if (! $callQueue) {
            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        app(QueueCallLifecycleService::class)->recordDialResult($context['call_id'], $callStatus);

        if (in_array($callStatus, ['busy', 'no-answer', 'failed'], true)) {
            Log::info('QueueDialCallbackController: Agent dial failed, offering to next agent immediately', [
                'call_queue_id' => $callQueue->id,
                'call_id' => $context['call_id'],
                'call_status' => $callStatus,
            ]);

            app(QueueCallLifecycleService::class)->handleDialFailed($callQueue, $context['call_id']);

            // Re-offer immediately: another agent may be available and the
            // caller should not wait out the hold cycle for the next offer.
            $queueCall = QueueCall::withoutGlobalScope(OrganizationScope::class)
                ->where('organization_id', $callQueue->organization_id)
                ->where('call_queue_id', $callQueue->id)
                ->where('call_id', $context['call_id'])
                ->whereNull('disposition')
                ->first();

            if ($queueCall) {
                $dialOffer = app(QueueDialOfferService::class);
                $result = app(AcdWorkerClient::class)->poll(
                    $callQueue->organization_id,
                    $callQueue->id,
                    $queueCall->call_id,
                    $dialOffer->roster($callQueue),
                    $callQueue->strategy->value,
                    $callQueue->max_wait_seconds
                );

                if (($result['action'] ?? 'wait') === 'dial') {
                    $dialResponse = $dialOffer->buildDialResponse($request, $callQueue, $queueCall, $result['agents'] ?? []);

                    if ($dialResponse !== null) {
                        return $dialResponse;
                    }
                }
            }

            return app(QueueRoutingStrategy::class)->holdResponse($request, $callQueue, $context['call_id'], null, $context['moh_idx']);
        }

        return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
    }
}
