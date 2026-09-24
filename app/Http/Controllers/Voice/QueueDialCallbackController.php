<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\QueueCallbackRequest;
use App\Models\CallQueue;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\QueueCallLifecycleService;
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
            'call_queue_id' => $context['call_queue_id'],
            'call_id' => $context['call_id'],
            'call_status' => $callStatus,
        ]);
        app(QueueCallLifecycleService::class)->recordDialResult($context['call_id'], $callStatus);

        if (in_array($callStatus, ['busy', 'no-answer', 'failed'], true)) {
            $callQueue = CallQueue::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $context['call_queue_id'])
                ->when($context['organization_id'], fn ($q, $orgId) => $q->where('organization_id', $orgId))
                ->first();

            if ($callQueue) {
                Log::info('QueueDialCallbackController: Agent dial failed, offering to next agent immediately', [
                    'call_queue_id' => $callQueue->id,
                    'call_id' => $context['call_id'],
                    'call_status' => $callStatus,
                ]);

                app(QueueCallLifecycleService::class)->handleDialFailed($callQueue, $context['call_id']);

                // Re-offer immediately: another agent may be available and the
                // caller should not wait out the hold cycle for the next offer.
                $queueCall = \App\Models\QueueCall::withoutGlobalScope(\App\Scopes\OrganizationScope::class)
                    ->where('call_queue_id', $callQueue->id)
                    ->where('call_id', $context['call_id'])
                    ->whereNull('disposition')
                    ->first();

                if ($queueCall) {
                    $dialOffer = app(\App\Services\CallQueue\QueueDialOfferService::class);
                    $result = app(\App\Services\CallQueue\AcdWorkerClient::class)->poll(
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

                return app(QueueRoutingStrategy::class)->holdResponse($request, $callQueue, $context['call_id']);
            }
        }

        return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
    }
}
