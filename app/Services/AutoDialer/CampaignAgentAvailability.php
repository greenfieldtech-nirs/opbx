<?php

declare(strict_types=1);

namespace App\Services\AutoDialer;

use App\Enums\RoutingDestinationType;
use App\Models\AutoDialerCampaign;
use App\Models\RingGroup;
use App\Scopes\OrganizationScope;
use App\Services\CallQueue\ExtensionPresenceTracker;

/**
 * "Wait for available agents": a ring group campaign stops dialing while no member of its ring group
 * can take a call (every active member extension is on a call or ringing, per extension presence).
 */
class CampaignAgentAvailability
{
    public function __construct(
        private readonly ExtensionPresenceTracker $presence,
    ) {}

    public function isWaitingForAgents(AutoDialerCampaign $campaign): bool
    {
        if (! $campaign->wait_for_available_agents || $campaign->routing_destination_type !== RoutingDestinationType::RING_GROUP) {
            return false;
        }

        // Dialer worker requests carry no user: read the campaign's own organization explicitly.
        $ringGroup = OrganizationScope::bypass(fn () => RingGroup::where('organization_id', $campaign->organization_id)
            ->with('members.extension')
            ->find($campaign->routing_destination_id));

        // A missing ring group is the routing's problem (the call hangs up), not a reason to stall.
        if ($ringGroup === null) {
            return false;
        }

        return ! $ringGroup->members->contains(fn ($member) => $member->extension?->isActive()
            && ! $this->presence->isBusy($campaign->organization_id, (string) $member->extension->extension_number));
    }
}
