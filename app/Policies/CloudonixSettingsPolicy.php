<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CloudonixSettings;
use App\Models\User;

/**
 * Policy for Cloudonix settings authorization.
 *
 * Only organization owners can view and manage Cloudonix settings.
 */
class CloudonixSettingsPolicy
{
    /**
     * Determine if the user can view any Cloudonix settings.
     *
     * Only organization owners can view settings.
     */
    public function viewAny(User $user): bool
    {
        return $user->role->canManageOrganization();
    }

    /**
     * Determine if the user can view the Cloudonix settings.
     *
     * Only organization owners can view settings, and only for their own organization.
     */
    public function view(User $user, CloudonixSettings $settings): bool
    {
        return $user->role->canManageOrganization()
            && $user->organization_id === $settings->organization_id;
    }

    /**
     * Determine if the user can create Cloudonix settings.
     *
     * Only organization owners can create settings.
     */
    public function create(User $user): bool
    {
        return $user->role->canManageOrganization();
    }

    /**
     * Determine if the user can update the Cloudonix settings.
     *
     * Only organization owners can update settings, and only for their own organization.
     */
    public function update(User $user, CloudonixSettings $settings): bool
    {
        return $user->role->canManageOrganization()
            && $user->organization_id === $settings->organization_id;
    }

    /**
     * Determine if the user can delete the Cloudonix settings.
     *
     * Only organization owners can delete settings, and only for their own organization.
     */
    public function delete(User $user, CloudonixSettings $settings): bool
    {
        return $user->role->canManageOrganization()
            && $user->organization_id === $settings->organization_id;
    }

    /**
     * Determine if the user can validate Cloudonix credentials.
     *
     * Only organization owners can validate credentials.
     */
    public function validate(User $user): bool
    {
        return $user->role->canManageOrganization();
    }

    /**
     * Determine if the user can generate API keys.
     *
     * Only organization owners can generate API keys.
     */
    public function generateApiKey(User $user): bool
    {
        return $user->role->canManageOrganization();
    }
}
