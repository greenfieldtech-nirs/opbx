<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\IvrMenu;
use App\Models\User;

/**
 * IVR menu authorization: Owner and PBX Admin write; every role in the same organization reads.
 */
class IvrMenuPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, IvrMenu $ivrMenu): bool
    {
        return $user->organization_id === $ivrMenu->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isPBXAdmin();
    }

    public function update(User $user, IvrMenu $ivrMenu): bool
    {
        return $user->organization_id === $ivrMenu->organization_id
            && ($user->isOwner() || $user->isPBXAdmin());
    }

    public function delete(User $user, IvrMenu $ivrMenu): bool
    {
        return $this->update($user, $ivrMenu);
    }
}
