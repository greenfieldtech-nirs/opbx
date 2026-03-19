<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\CampaignStatus;
use App\Models\AutoDialerCampaign;
use App\Models\User;

class AutoDialerCampaignPolicy
{
    /**
     * Determine whether the user can view any campaigns.
     *
     * Only Owner and PBX Admin can view campaigns.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->canManageUsers();
    }

    /**
     * Determine whether the user can view the campaign.
     *
     * User must belong to the same organization.
     */
    public function view(User $user, AutoDialerCampaign $campaign): bool
    {
        return $user->organization_id === $campaign->organization_id;
    }

    /**
     * Determine whether the user can create campaigns.
     *
     * Only Owner and PBX Admin can create campaigns.
     */
    public function create(User $user): bool
    {
        return $user->role->canManageUsers();
    }

    /**
     * Determine whether the user can update the campaign.
     *
     * User must be in same organization and have manage permission.
     * Cannot update if campaign is active.
     */
    public function update(User $user, AutoDialerCampaign $campaign): bool
    {
        if ($user->organization_id !== $campaign->organization_id) {
            return false;
        }

        if (! $user->role->canManageUsers()) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can delete the campaign.
     *
     * Only Owner can delete campaigns, and only if they are in draft status.
     */
    public function delete(User $user, AutoDialerCampaign $campaign): bool
    {
        if ($user->organization_id !== $campaign->organization_id) {
            return false;
        }

        // Only draft campaigns can be deleted
        if ($campaign->status !== CampaignStatus::DRAFT) {
            return false;
        }

        return $user->role->isOwner();
    }

    /**
     * Determine whether the user can start the campaign.
     *
     * User must have manage permission and campaign must be startable.
     */
    public function start(User $user, AutoDialerCampaign $campaign): bool
    {
        if ($user->organization_id !== $campaign->organization_id) {
            return false;
        }

        if (! $user->role->canManageUsers()) {
            return false;
        }

        return $campaign->canStart();
    }

    /**
     * Determine whether the user can pause the campaign.
     *
     * User must have manage permission and campaign must be active.
     */
    public function pause(User $user, AutoDialerCampaign $campaign): bool
    {
        if ($user->organization_id !== $campaign->organization_id) {
            return false;
        }

        if (! $user->role->canManageUsers()) {
            return false;
        }

        return $campaign->canPause();
    }

    /**
     * Determine whether the user can resume the campaign.
     *
     * User must have manage permission and campaign must be paused.
     */
    public function resume(User $user, AutoDialerCampaign $campaign): bool
    {
        if ($user->organization_id !== $campaign->organization_id) {
            return false;
        }

        if (! $user->role->canManageUsers()) {
            return false;
        }

        return $campaign->status === CampaignStatus::PAUSED;
    }

    /**
     * Determine whether the user can archive the campaign.
     *
     * Only Owner can archive campaigns.
     */
    public function archive(User $user, AutoDialerCampaign $campaign): bool
    {
        if ($user->organization_id !== $campaign->organization_id) {
            return false;
        }

        return $user->role->isOwner();
    }

    /**
     * Determine whether the user can upload a list to the campaign.
     *
     * User must have manage permission.
     */
    public function uploadList(User $user, AutoDialerCampaign $campaign): bool
    {
        if ($user->organization_id !== $campaign->organization_id) {
            return false;
        }

        if (! $user->role->canManageUsers()) {
            return false;
        }

        // Cannot upload if campaign already has a list
        if ($campaign->hasList()) {
            return false;
        }

        return true;
    }

    /**
     * Determine whether the user can delete the list from the campaign.
     *
     * User must have manage permission and campaign must not be active.
     */
    public function deleteList(User $user, AutoDialerCampaign $campaign): bool
    {
        if ($user->organization_id !== $campaign->organization_id) {
            return false;
        }

        if (! $user->role->canManageUsers()) {
            return false;
        }

        // Cannot delete list if campaign is active
        if ($campaign->status === CampaignStatus::ACTIVE) {
            return false;
        }

        return $campaign->hasList();
    }
}
