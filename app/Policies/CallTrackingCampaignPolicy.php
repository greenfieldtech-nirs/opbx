<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CallTrackingCampaign;
use App\Models\User;

/**
 * Call Tracking Campaign authorization policy.
 *
 * Authorization rules:
 * - Owner: Full access
 * - PBX Admin: Full access
 * - PBX User: Can view
 * - Reporter: Can view (read-only)
 */
class CallTrackingCampaignPolicy
{
    /**
     * Determine if the user can view the campaigns list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can view a specific campaign.
     */
    public function view(User $user, CallTrackingCampaign $campaign): bool
    {
        return $user->organization_id === $campaign->organization_id;
    }

    /**
     * Determine if the user can create campaigns.
     */
    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update a campaign.
     */
    public function update(User $user, CallTrackingCampaign $campaign): bool
    {
        if ($user->organization_id !== $campaign->organization_id) {
            return false;
        }

        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can delete a campaign.
     */
    public function delete(User $user, CallTrackingCampaign $campaign): bool
    {
        if ($user->organization_id !== $campaign->organization_id) {
            return false;
        }

        return $user->isOwner() || $user->isPBXAdmin();
    }
}
