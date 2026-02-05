<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiAssistant;
use App\Models\User;

/**
 * AI Assistant Policy
 *
 * Handles authorization for AI Assistant operations.
 */
class AiAssistantPolicy
{
    /**
     * Determine if the user can view any AI assistants.
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view AI assistants in their organization
        return true;
    }

    /**
     * Determine if the user can view the AI assistant.
     */
    public function view(User $user, AiAssistant $aiAssistant): bool
    {
        // User can view if the assistant belongs to their organization
        return $user->organization_id === $aiAssistant->organization_id;
    }

    /**
     * Determine if the user can create AI assistants.
     */
    public function create(User $user): bool
    {
        // Only Owner and PBX Admin can create AI assistants
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update the AI assistant.
     */
    public function update(User $user, AiAssistant $aiAssistant): bool
    {
        // Only Owner and PBX Admin can update AI assistants
        // And the assistant must belong to their organization
        return ($user->isOwner() || $user->isPBXAdmin())
            && $user->organization_id === $aiAssistant->organization_id;
    }

    /**
     * Determine if the user can delete the AI assistant.
     */
    public function delete(User $user, AiAssistant $aiAssistant): bool
    {
        // Only Owner and PBX Admin can delete AI assistants
        // And the assistant must belong to their organization
        return ($user->isOwner() || $user->isPBXAdmin())
            && $user->organization_id === $aiAssistant->organization_id;
    }
}
