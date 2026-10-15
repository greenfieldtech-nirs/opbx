<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Webhook notification settings and delivery logs (which can expose request
 * headers and payloads): Owner and PBX Admin only.
 */
class CallNotificationsSettingsPolicy
{
    public function viewAny(User $user): bool
    {
        return ($user->isOwner() || $user->isPBXAdmin()) && $user->organization_id !== null;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user): bool
    {
        return $this->viewAny($user);
    }
}
