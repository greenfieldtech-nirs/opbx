<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AutoDialerList;
use App\Models\User;

class DistributionListPolicy
{
    /**
     * Determine whether the user can view any lists.
     */
    public function viewAny(User $user): bool
    {
        // Owner and PBX Admin can view all lists in their organization
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine whether the user can view the list.
     */
    public function view(User $user, AutoDialerList $list): bool
    {
        // Must be in same organization
        return $user->organization_id === $list->organization_id;
    }

    /**
     * Determine whether the user can create lists.
     */
    public function create(User $user): bool
    {
        // Only Owner and PBX Admin can create
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine whether the user can update the list.
     *
     * Note: Only name/description can be updated, not destinations.
     */
    public function update(User $user, AutoDialerList $list): bool
    {
        // Must be in same organization and have management role
        // Cannot update if list is locked (in_use or used)
        if ($user->organization_id !== $list->organization_id) {
            return false;
        }

        if (! $user->isOwner() && ! $user->isPBXAdmin()) {
            return false;
        }

        return ! $list->isLocked();
    }

    /**
     * Determine whether the user can upload destinations to the list.
     */
    public function upload(User $user, AutoDialerList $list): bool
    {
        // Must be in same organization and have management role
        if ($user->organization_id !== $list->organization_id) {
            return false;
        }

        if (! $user->isOwner() && ! $user->isPBXAdmin()) {
            return false;
        }

        // Can only upload to lists that allow it
        return $list->status->canUpload();
    }

    /**
     * Determine whether the user can archive the list.
     */
    public function archive(User $user, AutoDialerList $list): bool
    {
        // Must be in same organization and have management role
        if ($user->organization_id !== $list->organization_id) {
            return false;
        }

        if (! $user->isOwner() && ! $user->isPBXAdmin()) {
            return false;
        }

        // Can only archive if status allows
        return $list->canBeArchived();
    }

    /**
     * Determine whether the user can copy the list.
     */
    public function copy(User $user, AutoDialerList $list): bool
    {
        // Must be in same organization and have management role
        if ($user->organization_id !== $list->organization_id) {
            return false;
        }

        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine whether the user can download the list.
     */
    public function download(User $user, AutoDialerList $list): bool
    {
        // Must be in same organization and have management role
        return $user->organization_id === $list->organization_id
            && ($user->isOwner() || $user->isPBXAdmin());
    }

    /**
     * Determine whether the user can create a new version of the list.
     */
    public function createVersion(User $user, AutoDialerList $list): bool
    {
        // Must be in same organization and have management role
        if ($user->organization_id !== $list->organization_id) {
            return false;
        }

        if (! $user->isOwner() && ! $user->isPBXAdmin()) {
            return false;
        }

        return $list->status->canCreateVersion();
    }

    /**
     * Determine whether the user can assign the list to a campaign.
     */
    public function assign(User $user, AutoDialerList $list): bool
    {
        // Must be in same organization and have management role
        if ($user->organization_id !== $list->organization_id) {
            return false;
        }

        if (! $user->isOwner() && ! $user->isPBXAdmin()) {
            return false;
        }

        // List must be ready
        return $list->isReady();
    }

    /**
     * Determine whether the user can unassign the list from a campaign.
     */
    public function unassign(User $user, AutoDialerList $list): bool
    {
        // Must be in same organization and have management role
        if ($user->organization_id !== $list->organization_id) {
            return false;
        }

        if (! $user->isOwner() && ! $user->isPBXAdmin()) {
            return false;
        }

        // List must be assigned to a campaign (in_use status)
        return $list->campaign_id !== null;
    }

    /**
     * Determine whether the user can delete the list.
     *
     * Note: Lists should normally be archived, not deleted.
     * Failed lists can be deleted by Owners and PBX Admins.
     * Only Owners can delete lists in other statuses.
     */
    public function delete(User $user, AutoDialerList $list): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $list->organization_id) {
            return false;
        }

        // Failed lists can be deleted by Owners and PBX Admins
        if ($list->status->value === 'failed') {
            return $user->isOwner() || $user->isPBXAdmin();
        }

        // Other lists can only be deleted by Owners
        return $user->isOwner();
    }

    /**
     * Determine whether the user can restore the list.
     */
    public function restore(User $user, AutoDialerList $list): bool
    {
        // Only Owners can restore
        return $user->organization_id === $list->organization_id
            && $user->isOwner();
    }

    /**
     * Determine whether the user can permanently delete the list.
     */
    public function forceDelete(User $user, AutoDialerList $list): bool
    {
        // Only Owners can force delete
        return $user->organization_id === $list->organization_id
            && $user->isOwner();
    }
}
