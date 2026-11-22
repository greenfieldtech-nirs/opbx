<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Http\Controllers\Controller;
use App\Scopes\OrganizationScope;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\CallTransferService;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Fetched (signed URL, no Bearer auth) by the session a blind transfer moved: routes the transfer
 * destination as a call dialled by the transferring extension, so extension routing, the outbound
 * whitelist and the organization's caller ID rules apply.
 */
final class CallTransferController extends Controller
{
    public function __invoke(Request $request, CallTransferService $transfers, VoiceRoutingManager $manager): Response
    {
        $context = $transfers->verifiedContext((string) $request->query('sd', ''), (string) $request->query('sig', ''));

        if ($context === null) {
            Log::warning('CallTransferController: invalid or expired signature');

            return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
        }

        $request->merge([
            '_organization_id' => (int) $context['organization_id'],
            'Direction' => 'subscriber',
            'From' => (string) $context['from'],
            'To' => (string) $context['to'],
        ]);

        return OrganizationScope::bypass(fn () => $manager->handleInbound($request));
    }
}
