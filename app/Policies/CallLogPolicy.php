<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CallLog;
use App\Models\User;

/**
 * Call Log Policy
 *
 * Handles authorization for call log operations (read-only access).
 */
class CallLogPolicy
{
    /**
     * Determine if the user can view any call logs.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view call logs in their organization
        return true;
    }

    /**
     * Determine if the user can view the call log.
     */
    public function view(User $user, CallLog $callLog): bool
    {
        // User can view if the call log belongs to their organization
        return $user->organization_id === $callLog->organization_id;
    }

    /**
     * Determine if the user can create call logs.
     */
    public function create(User $user): bool
    {
        // Call logs are system-generated via webhooks
        return false;
    }

    /**
     * Determine if the user can update the call log.
     */
    public function update(User $user, CallLog $callLog): bool
    {
        // Call logs are immutable
        return false;
    }

    /**
     * Determine if the user can delete the call log.
     */
    public function delete(User $user, CallLog $callLog): bool
    {
        // Call logs should not be deleted (audit trail)
        return false;
    }
}
