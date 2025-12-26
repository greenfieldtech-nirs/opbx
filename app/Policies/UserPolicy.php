<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * User authorization policy.
 *
 * Defines authorization rules for user management operations
 * based on the role-based access control system.
 *
 * Role hierarchy:
 * - Owner: Full access to all users and can manage roles
 * - PBX Admin: Can view and update users but cannot change roles
 * - PBX User: Can only view and update their own profile
 * - Reporter: Read-only access, cannot manage users
 */
class UserPolicy
{
    /**
     * Determine if the user can view the users list.
     *
     * Only Owner and PBX Admin can view all users.
     *
     * @param  User  $user  The authenticated user
     * @return bool True if authorized to view users list
     */
    public function viewAny(User $user): bool
    {
        return $user->role->canManageUsers();
    }

    /**
     * Determine if the user can view another user's details.
     *
     * - Owner and PBX Admin can view any user
     * - PBX User can only view themselves
     * - Reporter cannot view user details
     *
     * @param  User  $authUser  The authenticated user
     * @param  User  $targetUser  The user being viewed
     * @return bool True if authorized to view the target user
     */
    public function view(User $authUser, User $targetUser): bool
    {
        // Owner and PBX Admin can view any user
        if ($authUser->role->canManageUsers()) {
            return true;
        }

        // PBX User can only view themselves
        if ($authUser->role->isPBXUser()) {
            return $authUser->id === $targetUser->id;
        }

        // Reporter cannot view user details
        return false;
    }

    /**
     * Determine if the user can update another user's information.
     *
     * - Owner and PBX Admin can update any user
     * - PBX User can only update themselves
     * - Reporter cannot update any user
     *
     * @param  User  $authUser  The authenticated user
     * @param  User  $targetUser  The user being updated
     * @return bool True if authorized to update the target user
     */
    public function update(User $authUser, User $targetUser): bool
    {
        // Owner and PBX Admin can update any user
        if ($authUser->role->canManageUsers()) {
            return true;
        }

        // PBX User can only update themselves
        if ($authUser->role->isPBXUser()) {
            return $authUser->id === $targetUser->id;
        }

        // Reporter cannot update any user
        return false;
    }

    /**
     * Determine if the user can change another user's role.
     *
     * - Only Owner can change roles
     * - Owner cannot change their own role (prevents lockout)
     * - Owner can change any other user's role
     *
     * @param  User  $authUser  The authenticated user
     * @param  User  $targetUser  The user whose role is being changed
     * @return bool True if authorized to change the target user's role
     */
    public function updateRole(User $authUser, User $targetUser): bool
    {
        // Only Owner can change roles
        if (!$authUser->role->isOwner()) {
            return false;
        }

        // Owner cannot change their own role to prevent lockout
        if ($authUser->id === $targetUser->id) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can delete another user.
     *
     * - Owner and PBX Admin can delete users
     * - Users cannot delete themselves (prevents lockout)
     * - Owner cannot be deleted by anyone
     *
     * @param  User  $authUser  The authenticated user
     * @param  User  $targetUser  The user being deleted
     * @return bool True if authorized to delete the target user
     */
    public function delete(User $authUser, User $targetUser): bool
    {
        // Owner and PBX Admin can delete users
        if (!$authUser->role->canManageUsers()) {
            return false;
        }

        // Users cannot delete themselves
        if ($authUser->id === $targetUser->id) {
            return false;
        }

        // Owner accounts cannot be deleted
        if ($targetUser->role->isOwner()) {
            return false;
        }

        return true;
    }
}
