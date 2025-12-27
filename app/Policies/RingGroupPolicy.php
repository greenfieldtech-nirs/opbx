<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RingGroup;
use App\Models\User;

/**
 * Ring Group authorization policy.
 *
 * Defines authorization rules for ring group management operations
 * based on the role-based access control system.
 *
 * Authorization rules:
 * - Owner: Full access to all ring groups
 * - PBX Admin: Full access to all ring groups
 * - PBX User: Can view ring groups they belong to
 * - Reporter: Can view all ring groups (read-only)
 */
class RingGroupPolicy
{
    /**
     * Determine if the user can view the ring groups list.
     *
     * All authenticated users can view ring groups within their organization.
     *
     * @param User $user The authenticated user
     * @return bool True if authorized to view ring groups list
     */
    public function viewAny(User $user): bool
    {
        // All roles can view ring groups
        return true;
    }

    /**
     * Determine if the user can view a specific ring group.
     *
     * Users can view any ring group within their organization.
     *
     * @param User $user The authenticated user
     * @param RingGroup $ringGroup The ring group being viewed
     * @return bool True if authorized to view the ring group
     */
    public function view(User $user, RingGroup $ringGroup): bool
    {
        // All roles can view ring groups within their organization
        return $user->organization_id === $ringGroup->organization_id;
    }

    /**
     * Determine if the user can create ring groups.
     *
     * Only Owner and PBX Admin can create ring groups.
     *
     * @param User $user The authenticated user
     * @return bool True if authorized to create ring groups
     */
    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update a ring group.
     *
     * Only Owner and PBX Admin can update ring groups.
     *
     * @param User $user The authenticated user
     * @param RingGroup $ringGroup The ring group being updated
     * @return bool True if authorized to update the ring group
     */
    public function update(User $user, RingGroup $ringGroup): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $ringGroup->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can update ring groups
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can delete a ring group.
     *
     * Only Owner and PBX Admin can delete ring groups.
     *
     * @param User $user The authenticated user
     * @param RingGroup $ringGroup The ring group being deleted
     * @return bool True if authorized to delete the ring group
     */
    public function delete(User $user, RingGroup $ringGroup): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $ringGroup->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can delete ring groups
        return $user->isOwner() || $user->isPBXAdmin();
    }
}
