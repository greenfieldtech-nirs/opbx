<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SessionUpdate;
use App\Models\User;

/**
 * Session Update Policy
 *
 * Handles authorization for session update operations.
 */
class SessionUpdatePolicy
{
    /**
     * Determine if the user can view any session updates.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view session updates in their organization
        return true;
    }

    /**
     * Determine if the user can view the session update.
     */
    public function view(User $user, SessionUpdate $sessionUpdate): bool
    {
        // User can view if the session belongs to their organization
        return $user->organization_id === $sessionUpdate->organization_id;
    }

    /**
     * Determine if the user can create session updates.
     */
    public function create(User $user): bool
    {
        // Session updates are system-generated via webhooks
        return false;
    }

    /**
     * Determine if the user can update the session update.
     */
    public function update(User $user, SessionUpdate $sessionUpdate): bool
    {
        // Session updates are immutable
        return false;
    }

    /**
     * Determine if the user can delete the session update.
     */
    public function delete(User $user, SessionUpdate $sessionUpdate): bool
    {
        // Session updates should not be deleted
        return false;
    }

    /**
     * Determine if the user can disconnect active sessions.
     */
    public function disconnect(User $user): bool
    {
        // Only the Owner can disconnect calls (canManageOrganization is owner-only).
        return $user->role->canManageOrganization();
    }
}
