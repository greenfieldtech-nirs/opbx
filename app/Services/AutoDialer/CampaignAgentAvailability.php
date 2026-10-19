<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\RoutingDestinationType;
use App\Models\AutoDialerCampaign;
use App\Models\CallQueue;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\AcdWorkerClient;
use App\Services\CallQueue\ExtensionPresenceTracker;
use App\Services\CallQueue\QueueDialOfferService;
use Illuminate\Support\Facades\Log;

/**
 * "Wait for available agents": a call queue campaign stops dialing while no agent of its queue can take
 * a call. Available = AVAILABLE in the ACD worker (logged in, not on a queue call, not in wrap-up) and
 * the extension not on another call (extension presence), as when the queue offers a call.
 */
class CampaignAgentAvailability
{
    public function __construct(
        private readonly AcdWorkerClient $worker,
        private readonly QueueDialOfferService $offers,
        private readonly ExtensionPresenceTracker $presence,
    ) {}

    public function isWaitingForAgents(AutoDialerCampaign $campaign): bool
    {
        if (! $campaign->wait_for_available_agents || $campaign->routing_destination_type !== RoutingDestinationType::CALL_QUEUE) {
            return false;
        }

        // Dialer worker requests carry no user: read the campaign's own organization explicitly.
        $callQueue = OrganizationScope::bypass(fn () => CallQueue::where('organization_id', $campaign->organization_id)
            ->find($campaign->routing_destination_id));

        // A missing queue is the routing's problem (the call hangs up), not a reason to stall.
        if ($callQueue === null) {
            return false;
        }

        $live = $this->worker->live($campaign->organization_id, $callQueue->id, $this->offers->roster($callQueue));

        // Agent states unknown (ACD worker unreachable): keep dialing, as without the setting.
        if (! is_array($live['agents'] ?? null)) {
            Log::warning('CampaignAgentAvailability: ACD worker unavailable, not holding dialing', [
                'campaign_id' => $campaign->id,
                'call_queue_id' => $callQueue->id,
            ]);

            return false;
        }

        return ! collect($live['agents'])->contains(fn (array $agent) => ($agent['state'] ?? null) === 'AVAILABLE'
            && ! $this->presence->isBusy($campaign->organization_id, (string) ($agent['extensionNumber'] ?? '')));
    }
}
