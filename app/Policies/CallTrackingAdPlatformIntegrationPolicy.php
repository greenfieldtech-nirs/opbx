<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class CallTrackingAdPlatformIntegrationPolicy
{
    public function view(User $user, Organization $organization): bool
    {
        return $user->organization_id === $organization->id
            && ($user->isOwner() || $user->isPBXAdmin());
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->organization_id === $organization->id
            && ($user->isOwner() || $user->isPBXAdmin());
    }
}
