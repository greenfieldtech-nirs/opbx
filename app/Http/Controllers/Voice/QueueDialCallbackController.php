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

        if (in_array($callStatus, ['busy', 'no-answer', 'failed'], true)) {
            $callQueue = CallQueue::withoutGlobalScope(OrganizationScope::class)
                ->where('id', $context['call_queue_id'])
                ->when($context['organization_id'], fn ($q, $orgId) => $q->where('organization_id', $orgId))
                ->first();

            if ($callQueue) {
                Log::info('QueueDialCallbackController: Agent dial failed, returning caller to queue', [
                    'call_queue_id' => $callQueue->id,
                    'call_id' => $context['call_id'],
                    'call_status' => $callStatus,
                ]);

                app(QueueCallLifecycleService::class)->handleDialFailed($callQueue, $context['call_id']);

                return app(QueueRoutingStrategy::class)->holdResponse($request, $callQueue, $context['call_id']);
            }
        }

        return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
    }
}
