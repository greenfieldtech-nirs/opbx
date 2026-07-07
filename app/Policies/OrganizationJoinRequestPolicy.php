<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\OrganizationJoinRequest;
use App\Models\User;

class OrganizationJoinRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::OWNER, UserRole::PBX_ADMIN], true);
    }

    public function approve(User $user, OrganizationJoinRequest $request): bool
    {
        return $user->organization_id === $request->organization_id
            && in_array($user->role, [UserRole::OWNER, UserRole::PBX_ADMIN], true);
    }

    public function reject(User $user, OrganizationJoinRequest $request): bool
    {
        return $this->approve($user, $request);
    }
}
