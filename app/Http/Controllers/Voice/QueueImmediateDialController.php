<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\QueueCall;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\AcdWorkerClient;
use App\Services\CallQueue\ImmediateConnectService;
use App\Services\CallQueue\QueueDialOfferService;
use App\Services\CxmlBuilder\CxmlBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Proactive (immediate connect) dial endpoint.
 *
 * Cloudonix fetches this URL after Laravel switches the session's voice
 * application mid-hold. The URL is HMAC-signed by ImmediateConnectService
 * (no voice Bearer auth — application-switched fetches may not carry it).
 * The offer is re-validated against the acd-worker at fetch time; if it is
 * no longer valid the caller simply continues holding.
 */
class QueueImmediateDialController extends Controller
{
    public function handle(Request $request): Response
    {
        $encoded = (string) $request->query('sd', '');
        $signature = (string) $request->query('sig', '');
        $payload = base64_decode($encoded, true);

        $context = is_string($payload) ? json_decode($payload, true) : null;

        $service = app(ImmediateConnectService::class);

        if (! is_array($context)
            || ! $service->verifySignature($payload, $signature)
            || ($context['exp'] ?? 0) < now()->getTimestamp()) {
            Log::warning('QueueImmediateDialController: invalid or expired signature');

            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        $callQueue = CallQueue::withoutGlobalScope(OrganizationScope::class)
            ->where('id', (int) ($context['call_queue_id'] ?? 0))
            ->where('organization_id', (int) ($context['organization_id'] ?? 0))
            ->first();

        if (! $callQueue) {
            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        $queueCall = QueueCall::withoutGlobalScope(OrganizationScope::class)
            ->where('call_queue_id', $callQueue->id)
            ->where('call_id', (string) ($context['call_id'] ?? ''))
            ->whereNull('disposition')
            ->first();

        if (! $queueCall) {
            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        // Re-validate the offer with the worker at fetch time.
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

        // Offer stale or agents busy: caller keeps holding.
        return $dialOffer->holdResponse($request, $callQueue, $queueCall->call_id);
    }
}
