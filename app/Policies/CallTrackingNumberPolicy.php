<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CallTrackingNumber;
use App\Models\User;

/**
 * Call Tracking Number authorization policy.
 *
 * Authorization rules:
 * - Owner: Full access
 * - PBX Admin: Full access
 * - PBX User: Can view
 * - Reporter: Can view (read-only)
 */
class CallTrackingNumberPolicy
{
    /**
     * Determine if the user can view the tracking numbers list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine if the user can view a specific tracking number.
     */
    public function view(User $user, CallTrackingNumber $number): bool
    {
        return $user->organization_id === $number->organization_id;
    }

    /**
     * Determine if the user can create tracking numbers.
     */
    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update a tracking number.
     */
    public function update(User $user, CallTrackingNumber $number): bool
    {
        if ($user->organization_id !== $number->organization_id) {
            return false;
        }

        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can delete a tracking number.
     */
    public function delete(User $user, CallTrackingNumber $number): bool
    {
        if ($user->organization_id !== $number->organization_id) {
            return false;
        }

        return $user->isOwner() || $user->isPBXAdmin();
    }
}
