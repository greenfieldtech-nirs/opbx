<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OutboundWhitelist;
use App\Models\User;

/**
 * Outbound Whitelist authorization policy.
 *
 * Defines authorization rules for outbound whitelist management operations
 * based on the role-based access control system.
 *
 * Authorization rules:
 * - Owner: Full access to all outbound whitelist entries
 * - PBX Admin: Full access to all outbound whitelist entries
 * - PBX User: Can view outbound whitelist entries (read-only)
 * - Reporter: Can view outbound whitelist entries (read-only)
 */
class OutboundWhitelistPolicy
{
    /**
     * Determine if the user can view the outbound whitelist list.
     *
     * All authenticated users can view outbound whitelist entries within their organization.
     *
     * @param User $user The authenticated user
     * @return bool True if authorized to view outbound whitelist list
     */
    public function viewAny(User $user): bool
    {
        // All roles can view outbound whitelist entries
        return true;
    }

    /**
     * Determine if the user can view a specific outbound whitelist entry.
     *
     * Users can view any outbound whitelist entry within their organization.
     *
     * @param User $user The authenticated user
     * @param OutboundWhitelist $outboundWhitelist The outbound whitelist entry being viewed
     * @return bool True if authorized to view the outbound whitelist entry
     */
    public function view(User $user, OutboundWhitelist $outboundWhitelist): bool
    {
        // All roles can view outbound whitelist entries within their organization
        return $user->organization_id === $outboundWhitelist->organization_id;
    }

    /**
     * Determine if the user can create outbound whitelist entries.
     *
     * Only Owner and PBX Admin can create outbound whitelist entries.
     *
     * @param User $user The authenticated user
     * @return bool True if authorized to create outbound whitelist entries
     */
    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update an outbound whitelist entry.
     *
     * Only Owner and PBX Admin can update outbound whitelist entries.
     *
     * @param User $user The authenticated user
     * @param OutboundWhitelist $outboundWhitelist The outbound whitelist entry being updated
     * @return bool True if authorized to update the outbound whitelist entry
     */
    public function update(User $user, OutboundWhitelist $outboundWhitelist): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $outboundWhitelist->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can update outbound whitelist entries
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can delete an outbound whitelist entry.
     *
     * Only Owner and PBX Admin can delete outbound whitelist entries.
     *
     * @param User $user The authenticated user
     * @param OutboundWhitelist $outboundWhitelist The outbound whitelist entry being deleted
     * @return bool True if authorized to delete the outbound whitelist entry
     */
    public function delete(User $user, OutboundWhitelist $outboundWhitelist): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $outboundWhitelist->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can delete outbound whitelist entries
        return $user->isOwner() || $user->isPBXAdmin();
    }
}