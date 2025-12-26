<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Extension;
use App\Models\User;

/**
 * Extension authorization policy.
 *
 * Defines authorization rules for extension management operations
 * based on the role-based access control system.
 *
 * Authorization rules:
 * - Owner: Full access to all extensions
 * - PBX Admin: Full access to all extensions
 * - PBX User: Can view all extensions, can update only their own extension
 * - Reporter: Can view all extensions, cannot modify
 */
class ExtensionPolicy
{
    /**
     * Determine if the user can view the extensions list.
     *
     * All authenticated users can view extensions within their organization.
     *
     * @param User $user The authenticated user
     * @return bool True if authorized to view extensions list
     */
    public function viewAny(User $user): bool
    {
        // All roles can view extensions
        return true;
    }

    /**
     * Determine if the user can view a specific extension.
     *
     * Users can view any extension within their organization.
     *
     * @param User $user The authenticated user
     * @param Extension $extension The extension being viewed
     * @return bool True if authorized to view the extension
     */
    public function view(User $user, Extension $extension): bool
    {
        // All roles can view extensions within their organization
        return $user->organization_id === $extension->organization_id;
    }

    /**
     * Determine if the user can create extensions.
     *
     * Only Owner and PBX Admin can create extensions.
     *
     * @param User $user The authenticated user
     * @return bool True if authorized to create extensions
     */
    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update an extension.
     *
     * - Owner and PBX Admin can update any extension
     * - PBX User can only update their own extension
     * - Reporter cannot update any extension
     *
     * @param User $user The authenticated user
     * @param Extension $extension The extension being updated
     * @return bool True if authorized to update the extension
     */
    public function update(User $user, Extension $extension): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $extension->organization_id) {
            return false;
        }

        // Owner and PBX Admin can update any extension
        if ($user->isOwner() || $user->isPBXAdmin()) {
            return true;
        }

        // PBX User can only update their own extension
        if ($user->isPBXUser()) {
            return $extension->belongsToUser($user->id);
        }

        // Reporter cannot update extensions
        return false;
    }

    /**
     * Determine if the user can delete an extension.
     *
     * Only Owner and PBX Admin can delete extensions.
     *
     * @param User $user The authenticated user
     * @param Extension $extension The extension being deleted
     * @return bool True if authorized to delete the extension
     */
    public function delete(User $user, Extension $extension): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $extension->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can delete extensions
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Helper method to check if a user owns the extension.
     *
     * @param User $user The authenticated user
     * @param Extension $extension The extension to check
     * @return bool True if the user owns the extension
     */
    protected function ownsExtension(User $user, Extension $extension): bool
    {
        return $extension->belongsToUser($user->id);
    }
}
