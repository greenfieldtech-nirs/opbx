<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DidNumber;
use App\Models\User;

/**
 * DID Number (Phone Number) authorization policy.
 *
 * Defines authorization rules for phone number management operations
 * based on the role-based access control system.
 *
 * Authorization rules:
 * - Owner: Full access to all phone numbers
 * - PBX Admin: Full access to all phone numbers
 * - PBX User: Can view phone numbers (read-only)
 * - Reporter: Can view all phone numbers (read-only)
 */
class DidNumberPolicy
{
    /**
     * Determine if the user can view the phone numbers list.
     *
     * All authenticated users can view phone numbers within their organization.
     *
     * @param User $user The authenticated user
     * @return bool True if authorized to view phone numbers list
     */
    public function viewAny(User $user): bool
    {
        // All roles can view phone numbers
        return true;
    }

    /**
     * Determine if the user can view a specific phone number.
     *
     * Users can view any phone number within their organization.
     *
     * @param User $user The authenticated user
     * @param DidNumber $didNumber The phone number being viewed
     * @return bool True if authorized to view the phone number
     */
    public function view(User $user, DidNumber $didNumber): bool
    {
        // All roles can view phone numbers within their organization
        return $user->organization_id === $didNumber->organization_id;
    }

    /**
     * Determine if the user can create phone numbers.
     *
     * Only Owner and PBX Admin can create phone numbers.
     *
     * @param User $user The authenticated user
     * @return bool True if authorized to create phone numbers
     */
    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update a phone number.
     *
     * Only Owner and PBX Admin can update phone numbers.
     *
     * @param User $user The authenticated user
     * @param DidNumber $didNumber The phone number being updated
     * @return bool True if authorized to update the phone number
     */
    public function update(User $user, DidNumber $didNumber): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $didNumber->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can update phone numbers
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can delete a phone number.
     *
     * Only Owner and PBX Admin can delete phone numbers.
     *
     * @param User $user The authenticated user
     * @param DidNumber $didNumber The phone number being deleted
     * @return bool True if authorized to delete the phone number
     */
    public function delete(User $user, DidNumber $didNumber): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $didNumber->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can delete phone numbers
        return $user->isOwner() || $user->isPBXAdmin();
    }
}
