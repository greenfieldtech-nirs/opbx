<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CallDetailRecord;
use App\Models\User;

/**
 * Call Detail Record Policy
 *
 * Handles authorization for CDR operations (read-only access).
 */
class CallDetailRecordPolicy
{
    /**
     * Determine if the user can view any call detail records.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view CDRs in their organization
        return true;
    }

    /**
     * Determine if the user can view the call detail record.
     */
    public function view(User $user, CallDetailRecord $callDetailRecord): bool
    {
        // User can view if the CDR belongs to their organization
        return $user->organization_id === $callDetailRecord->organization_id;
    }

    /**
     * Determine if the user can create call detail records.
     */
    public function create(User $user): bool
    {
        // CDRs are system-generated, not user-creatable
        return false;
    }

    /**
     * Determine if the user can update the call detail record.
     */
    public function update(User $user, CallDetailRecord $callDetailRecord): bool
    {
        // CDRs are immutable
        return false;
    }

    /**
     * Determine if the user can delete the call detail record.
     */
    public function delete(User $user, CallDetailRecord $callDetailRecord): bool
    {
        // CDRs should not be deleted (audit trail)
        return false;
    }
}
