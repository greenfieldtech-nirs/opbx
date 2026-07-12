<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Only organization Owners (authenticated as Users) may manage API keys.
 * A request authenticated by an ApiKey is never permitted — keys cannot
 * mint or manage keys (no privilege-escalation loop).
 */
class ApiKeyPolicy
{
    /**
     * Runs before all checks. Deny outright if the actor is an ApiKey.
     *
     * Note: EnforceApiKeyScope already 403s key requests on non-grantable
     * api-keys routes before reaching here; this is belt-and-suspenders. Also,
     * the global Gate::before short-circuits ApiKey to true, so this before()
     * is not the effective gate.
     */
    public function before(mixed $actor): ?Response
    {
        if ($actor instanceof ApiKey) {
            return Response::deny('API keys cannot manage API keys.');
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::OWNER;
    }

    public function view(User $user, ApiKey $apiKey): bool
    {
        return $user->role === UserRole::OWNER && $user->organization_id === $apiKey->organization_id;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::OWNER;
    }

    public function update(User $user, ApiKey $apiKey): bool
    {
        return $this->view($user, $apiKey);
    }

    public function delete(User $user, ApiKey $apiKey): bool
    {
        return $this->view($user, $apiKey);
    }
}
