<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\InboundBlacklist;
use App\Models\User;

class InboundBlacklistPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // All authenticated users can view
    }

    public function view(User $user, InboundBlacklist $blacklist): bool
    {
        return $user->organization_id === $blacklist->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::OWNER || $user->role === UserRole::PBX_ADMIN;
    }

    public function update(User $user, InboundBlacklist $blacklist): bool
    {
        return $user->organization_id === $blacklist->organization_id
            && ($user->role === UserRole::OWNER || $user->role === UserRole::PBX_ADMIN);
    }

    public function delete(User $user, InboundBlacklist $blacklist): bool
    {
        return $user->organization_id === $blacklist->organization_id
            && ($user->role === UserRole::OWNER || $user->role === UserRole::PBX_ADMIN);
    }
}
