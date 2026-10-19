<?php

declare(strict_types=1);

namespace App\Http\Controllers\Voice;

use App\Enums\ExtensionType;
use App\Enums\RoutingDestinationType;
use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\DidNumber;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\CloudonixSettingsResolver;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * An answered auto-dialer call routed to a ring group or a call queue.
 *
 * The campaign's CXML (fixed when the call is originated, before the session exists) redirects here
 * once the contact answers; the live request carries the session, so the call is handed to the same
 * ring group / call queue routing as an inbound call. The URL is a relative signed route, like the
 * other originated-session callbacks (their fetches may not carry the voice Bearer auth).
 */
class CampaignRouteController extends Controller
{
    // The call is answered within the origination deadline (5 minutes) plus the ring timeout.
    private const URL_VALID_MINUTES = 15;

    public static function signedUrl(int $organizationId, RoutingDestinationType $type, int $destinationId): string
    {
        return app(CloudonixSettingsResolver::class)->webhookBaseUrl($organizationId)
            .URL::temporarySignedRoute('voice.campaign-route', now()->addMinutes(self::URL_VALID_MINUTES), [
                'organization_id' => $organizationId,
                'type' => $type->value,
                'destination_id' => $destinationId,
            ], absolute: false);
    }

    public function __invoke(Request $request, VoiceRoutingManager $routing): Response
    {
        if (! $request->hasValidRelativeSignature()) {
            Log::warning('CampaignRouteController: invalid or expired signature');

            return $this->hangup();
        }

        $organizationId = (int) $request->query('organization_id');
        $destinationId = (int) $request->query('destination_id');
        $type = RoutingDestinationType::tryFrom((string) $request->query('type'));

        // The dialer calls the contact from the campaign's caller ID: to the ring group or queue the
        // contact is the caller.
        $request->merge([
            'From' => $request->input('To'),
            'To' => $request->input('From'),
            '_organization_id' => $organizationId,
            '_call_category' => 'outbound',
        ]);

        return OrganizationScope::bypass(function () use ($routing, $request, $type, $organizationId, $destinationId): Response {
            [$extensionType, $destination] = match ($type) {
                RoutingDestinationType::RING_GROUP => [ExtensionType::RING_GROUP, ['ring_group' => RingGroup::where('organization_id', $organizationId)->find($destinationId)]],
                RoutingDestinationType::CALL_QUEUE => [ExtensionType::QUEUE, ['call_queue' => CallQueue::where('organization_id', $organizationId)->find($destinationId)]],
                default => [null, []],
            };

            if ($extensionType === null || in_array(null, $destination, true)) {
                Log::warning('CampaignRouteController: campaign destination not found', [
                    'organization_id' => $organizationId,
                    'type' => $type?->value,
                    'destination_id' => $destinationId,
                ]);

                return $this->hangup();
            }

            Log::info('CampaignRouteController: routing answered campaign call', [
                'organization_id' => $organizationId,
                'type' => $type->value,
                'destination_id' => $destinationId,
                'call_sid' => $request->input('CallSid'),
            ]);

            return $routing->executeStrategy($extensionType, $request, new DidNumber, $destination);
        });
    }

    private function hangup(): Response
    {
        return response(CxmlBuilder::simpleHangup(), 200, ['Content-Type' => 'application/xml']);
    }
}
