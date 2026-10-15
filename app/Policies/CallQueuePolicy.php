<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CallQueue;
use App\Models\User;
use App\Services\Supervisor\SupervisorFilterService;

/**
 * Call Queue authorization policy.
 *
 * - Owner / PBX Admin: full access
 * - PBX User / Reporter: read-only within their organization
 * - Supervisor: read-only on queues where an assigned user (or they) is an agent
 */
class CallQueuePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, CallQueue $callQueue): bool
    {
        if ($user->organization_id !== $callQueue->organization_id) {
            return false;
        }

        if ($user->isSupervisor()) {
            return in_array($callQueue->id, app(SupervisorFilterService::class)->callQueueIds($user), true);
        }

        return $user->role->canManageConfiguration()
            || $user->role === UserRole::PBX_USER
            || $user->role === UserRole::REPORTER;
    }

    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isPBXAdmin();
    }

    public function update(User $user, CallQueue $callQueue): bool
    {
        return $user->organization_id === $callQueue->organization_id
            && ($user->isOwner() || $user->isPBXAdmin());
    }

    public function delete(User $user, CallQueue $callQueue): bool
    {
        return $this->update($user, $callQueue);
    }
}
