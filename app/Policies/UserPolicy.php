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
     * Determine if the user can create a new user.
     *
     * Only Owner and PBX Admin can create users.
     *
     * @param  User  $user  The authenticated user
     * @return bool True if authorized to create users
     */
    public function create(User $user): bool
    {
        return $user->role->canManageUsers();
    }

    /**
     * Determine if the user can view another user's details.
     *
     * - Owner and PBX Admin can view any user
     * - PBX User can only view themselves
     * - Supervisor can view themselves and their assigned users
     * - Reporter cannot view user details
     *
     * @param  User  $currentUser  The authenticated user
     * @param  User  $user  The user being viewed
     * @return bool True if authorized to view the user
     */
    public function view(User $currentUser, User $user): bool
    {
        // Cross-tenant access is never allowed
        if ($currentUser->organization_id !== $user->organization_id) {
            return false;
        }

        if ($currentUser->isSupervisor()) {
            return $currentUser->id === $user->id || $currentUser->supervisedUsers->contains($user);
        }

        if ($currentUser->role->canManageUsers()) {
            return true;
        }

        return $currentUser->id === $user->id;
    }

    /**
     * Determine if the user can assign supervisors.
     *
     * Only Owner and PBX Admin can assign supervisors.
     *
     * @param  User  $currentUser  The authenticated user
     * @return bool True if authorized to assign supervisors
     */
    public function assignAsSupervisor(User $currentUser): bool
    {
        return $currentUser->role->canAssignSupervisors();
    }

    /**
     * Determine if the user can view supervisor assignments.
     *
     * - Owner and PBX Admin can view any supervisor's assignments
     * - Supervisors can view their own assignments
     *
     * @param  User  $currentUser  The authenticated user
     * @param  User  $supervisor  The supervisor whose assignments are being viewed
     * @return bool True if authorized to view the supervisor's assignments
     */
    public function viewSupervisorAssignments(User $currentUser, User $supervisor): bool
    {
        // Cross-tenant access is never allowed
        if ($currentUser->organization_id !== $supervisor->organization_id) {
            return false;
        }

        return $currentUser->role->canAssignSupervisors() || $currentUser->id === $supervisor->id;
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
        // Cross-tenant access is never allowed
        if ($authUser->organization_id !== $targetUser->organization_id) {
            return false;
        }

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
        // Cross-tenant access is never allowed
        if ($authUser->organization_id !== $targetUser->organization_id) {
            return false;
        }

        // Only Owner can change roles
        if (! $authUser->role->isOwner()) {
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
     * - Controller handles "last owner" business logic
     *
     * @param  User  $authUser  The authenticated user
     * @param  User  $targetUser  The user being deleted
     * @return bool True if authorized to delete the target user
     */
    public function delete(User $authUser, User $targetUser): bool
    {
        // Cross-tenant access is never allowed
        if ($authUser->organization_id !== $targetUser->organization_id) {
            return false;
        }

        // Owner and PBX Admin can delete users
        if (! $authUser->role->canManageUsers()) {
            return false;
        }

        // Users cannot delete themselves
        if ($authUser->id === $targetUser->id) {
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can change another user's password.
     *
     * - Owner can change any user's password (except their own through this UI)
     * - PBX Admin can change PBX User and Reporter passwords
     * - Users cannot change their own password through this UI (should use profile/settings)
     *
     * @param  User  $authUser  The authenticated user
     * @param  User  $targetUser  The user whose password is being changed
     * @return bool True if authorized to change the target user's password
     */
    public function updatePassword(User $authUser, User $targetUser): bool
    {
        // Cross-tenant access is never allowed
        if ($authUser->organization_id !== $targetUser->organization_id) {
            return false;
        }

        // Users cannot change their own password through this UI
        if ($authUser->id === $targetUser->id) {
            return false;
        }

        // Owner can change any user's password
        if ($authUser->role->isOwner()) {
            return true;
        }

        // PBX Admin can only change PBX User and Reporter passwords
        if ($authUser->role->isPBXAdmin()) {
            return $targetUser->role->isPBXUser() || $targetUser->role->isReporter();
        }

        return false;
    }
}
