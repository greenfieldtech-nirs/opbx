<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ConferenceRoom;
use App\Models\User;

/**
 * Conference Room Policy
 *
 * Handles authorization for conference room operations.
 */
class ConferenceRoomPolicy
{
    /**
     * Determine if the user can view any conference rooms.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view conference rooms in their organization
        return true;
    }

    /**
     * Determine if the user can view the conference room.
     */
    public function view(User $user, ConferenceRoom $conferenceRoom): bool
    {
        // User can view if the room belongs to their organization
        return $user->organization_id === $conferenceRoom->organization_id;
    }

    /**
     * Determine if the user can create conference rooms.
     */
    public function create(User $user): bool
    {
        // Only Owner and PBX Admin can create conference rooms
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update the conference room.
     */
    public function update(User $user, ConferenceRoom $conferenceRoom): bool
    {
        // Only Owner and PBX Admin can update conference rooms
        // And the room must belong to their organization
        return ($user->isOwner() || $user->isPBXAdmin())
            && $user->organization_id === $conferenceRoom->organization_id;
    }

    /**
     * Determine if the user can delete the conference room.
     */
    public function delete(User $user, ConferenceRoom $conferenceRoom): bool
    {
        // Only Owner and PBX Admin can delete conference rooms
        // And the room must belong to their organization
        return ($user->isOwner() || $user->isPBXAdmin())
            && $user->organization_id === $conferenceRoom->organization_id;
    }
}
