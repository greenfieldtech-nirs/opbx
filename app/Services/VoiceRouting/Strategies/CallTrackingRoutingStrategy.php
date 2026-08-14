<?php

declare(strict_types=1);

namespace App\Services\VoiceRouting\Strategies;

use App\Enums\CallTrackingDestinationType;
use App\Enums\ExtensionType;
use App\Models\CallTrackingNumber;
use App\Models\DidNumber;
use App\Scopes\OrganizationScope;
use App\Services\CallRecording\CallRecordingDecisionService;
use App\Services\CallTracking\CallTrackingDestinationResolver;
use App\Services\CxmlBuilder\CxmlBuilder;
use App\Services\VoiceRouting\VoiceRoutingStrategyExecutor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Routing strategy for call tracking DIDs.
 *
 * This strategy is invoked directly by InboundRoutingService for DIDs whose
 * routing_type is "call_tracking". It resolves the active campaign and either
 * forwards to an external number or delegates to an existing OPBX strategy.
 */
class CallTrackingRoutingStrategy implements RoutingStrategy
{
    public function __construct(
        private readonly VoiceRoutingStrategyExecutor $strategyExecutor,
        private readonly CallTrackingDestinationResolver $destinationResolver
    ) {}

    /**
     * Always returns false because selection is driven by DID routing_type.
     */
    public function canHandle(ExtensionType $type): bool
    {
        return false;
    }

    /**
     * Route an inbound call to the campaign destination.
     *
     * @param  array<string, mixed>  $destination
     */
    public function route(Request $request, DidNumber $did, array $destination): Response
    {
        if ($did->routing_type !== 'call_tracking') {
            return $this->unavailableResponse('Invalid routing configuration');
        }

        Log::info('CallTrackingRoutingStrategy: Routing call tracking DID', [
            'did_id' => $did->id,
            'phone_number' => $did->phone_number,
            'organization_id' => $did->organization_id,
        ]);

        $trackingNumber = CallTrackingNumber::withoutGlobalScope(OrganizationScope::class)
            ->where('did_number_id', $did->id)
            ->where('organization_id', $did->organization_id)
            ->where('status', 'active')
            ->with(['campaign' => function ($query) {
                $query->withoutGlobalScope(OrganizationScope::class);
            }])
            ->first();

        if (! $trackingNumber || ! $trackingNumber->campaign) {
            Log::warning('CallTrackingRoutingStrategy: No active tracking number for DID', [
                'did_id' => $did->id,
            ]);

            return $this->unavailableResponse('Call tracking number not configured');
        }

        $campaign = $trackingNumber->campaign;

        if (! $campaign->isActive()) {
            Log::warning('CallTrackingRoutingStrategy: Campaign is inactive', [
                'did_id' => $did->id,
                'campaign_id' => $campaign->id,
            ]);

            return $this->unavailableResponse('Campaign is inactive');
        }

        if ($campaign->destination_type === CallTrackingDestinationType::FORWARD) {
            $forwardTo = $campaign->getForwardTo();

            if (! $forwardTo) {
                return $this->unavailableResponse('Forward destination not configured');
            }

            Log::info('CallTrackingRoutingStrategy: Forwarding to external number', [
                'did_id' => $did->id,
                'campaign_id' => $campaign->id,
                'forward_to' => $forwardTo,
            ]);

            // Call tracking DIDs are always reached by an external caller.
            $recording = app(CallRecordingDecisionService::class)->resolve(
                $did->organization_id,
                'inbound'
            );

            return response(
                CxmlBuilder::simpleDial($forwardTo, null, null, null, null, $recording->record, $recording->recordingStatusCallback),
                200,
                ['Content-Type' => 'application/xml']
            );
        }

        $resolvedDestination = $this->destinationResolver->resolve($campaign);

        if (! $resolvedDestination || ! isset($resolvedDestination['type'])) {
            Log::warning('CallTrackingRoutingStrategy: Could not resolve campaign destination', [
                'did_id' => $did->id,
                'campaign_id' => $campaign->id,
                'destination_type' => $campaign->destination_type->value,
            ]);

            return $this->unavailableResponse('Destination not configured');
        }

        Log::info('CallTrackingRoutingStrategy: Delegating to OPBX strategy', [
            'did_id' => $did->id,
            'campaign_id' => $campaign->id,
            'destination_type' => $campaign->destination_type->value,
            'extension_type' => $resolvedDestination['type']->value,
        ]);

        return $this->strategyExecutor->executeStrategy(
            $resolvedDestination['type'],
            $request,
            $did,
            $resolvedDestination
        );
    }

    /**
     * Create a standard unavailable CXML response.
     */
    private function unavailableResponse(string $message): Response
    {
        return response(
            CxmlBuilder::unavailable($message),
            200,
            ['Content-Type' => 'application/xml']
        );
    }
}
