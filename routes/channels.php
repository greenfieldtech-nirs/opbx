<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

/**
 * Organization Presence Channel
 *
 * Users can join their organization's presence channel to receive
 * real-time call updates and see other online users from their org.
 *
 * Authorization: User must belong to the organization
 * Returns: User info (id, name, role) for presence list
 */
Broadcast::channel('presence.org.{organizationId}', function ($user, $organizationId) {
    // Check if user belongs to this organization
    if ((string) $user->organization_id !== (string) $organizationId) {
        return false;
    }

    // Return user data to be shared with other channel members
    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->role->value,
    ];
});

/**
 * Private User Channel
 *
 * Each user has a private channel for user-specific notifications
 *
 * Authorization: User ID must match the channel user ID
 */
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (string) $user->id === (string) $userId;
});

/**
 * Extension Presence Channel
 *
 * Users can subscribe to specific extension status updates
 *
 * Authorization: User must belong to the same organization as the extension
 */
Broadcast::channel('extension.{extensionId}', function ($user, $extensionId) {
    // Load extension with organization relationship
    $extension = \App\Models\Extension::with('organization')->find($extensionId);

    if (!$extension) {
        return false;
    }

    // User must be in the same organization
    return (string) $user->organization_id === (string) $extension->organization_id;
});
