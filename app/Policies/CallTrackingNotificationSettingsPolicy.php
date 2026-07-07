<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CallTrackingNotificationSettings;
use App\Models\User;

/**
 * Call Tracking Notification Settings authorization policy.
 *
 * Authorization rules:
 * - Owner: Full access
 * - PBX Admin: Full access
 * - PBX User: Denied
 * - Reporter: Denied
 */
class CallTrackingNotificationSettingsPolicy
{
    /**
     * Determine if the user can view notification settings.
     */
    public function view(User $user, CallTrackingNotificationSettings $settings): bool
    {
        if ($user->organization_id !== $settings->organization_id) {
            return false;
        }

        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update notification settings.
     */
    public function update(User $user, CallTrackingNotificationSettings $settings): bool
    {
        return $this->view($user, $settings);
    }
}
