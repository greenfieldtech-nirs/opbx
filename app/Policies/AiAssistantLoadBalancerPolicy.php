<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AiAssistantLoadBalancer;
use App\Models\User;

/**
 * AI Assistant Load Balancer authorization policy.
 *
 * Defines authorization rules for AI Assistant Load Balancer management operations
 * based on the role-based access control system.
 *
 * Authorization rules:
 * - Owner: Full access to all AI Assistant Load Balancers
 * - PBX Admin: Full access to all AI Assistant Load Balancers
 * - PBX User: Can view AI Assistant Load Balancers
 * - Reporter: Can view AI Assistant Load Balancers (read-only)
 */
class AiAssistantLoadBalancerPolicy
{
    /**
     * Determine if the user can view the AI Assistant Load Balancers list.
     *
     * All authenticated users can view AI Assistant Load Balancers within their organization.
     *
     * @param  User  $user  The authenticated user
     * @return bool True if authorized to view AI Assistant Load Balancers list
     */
    public function viewAny(User $user): bool
    {
        // All roles can view AI Assistant Load Balancers
        return true;
    }

    /**
     * Determine if the user can view a specific AI Assistant Load Balancer.
     *
     * Users can view any AI Assistant Load Balancer within their organization.
     *
     * @param  User  $user  The authenticated user
     * @param  AiAssistantLoadBalancer  $loadBalancer  The AI Assistant Load Balancer being viewed
     * @return bool True if authorized to view the AI Assistant Load Balancer
     */
    public function view(User $user, AiAssistantLoadBalancer $loadBalancer): bool
    {
        // All roles can view AI Assistant Load Balancers within their organization
        return $user->organization_id === $loadBalancer->organization_id;
    }

    /**
     * Determine if the user can create AI Assistant Load Balancers.
     *
     * Only Owner and PBX Admin can create AI Assistant Load Balancers.
     *
     * @param  User  $user  The authenticated user
     * @return bool True if authorized to create AI Assistant Load Balancers
     */
    public function create(User $user): bool
    {
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can update an AI Assistant Load Balancer.
     *
     * Only Owner and PBX Admin can update AI Assistant Load Balancers.
     *
     * @param  User  $user  The authenticated user
     * @param  AiAssistantLoadBalancer  $loadBalancer  The AI Assistant Load Balancer being updated
     * @return bool True if authorized to update the AI Assistant Load Balancer
     */
    public function update(User $user, AiAssistantLoadBalancer $loadBalancer): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $loadBalancer->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can update AI Assistant Load Balancers
        return $user->isOwner() || $user->isPBXAdmin();
    }

    /**
     * Determine if the user can delete an AI Assistant Load Balancer.
     *
     * Only Owner and PBX Admin can delete AI Assistant Load Balancers.
     *
     * @param  User  $user  The authenticated user
     * @param  AiAssistantLoadBalancer  $loadBalancer  The AI Assistant Load Balancer being deleted
     * @return bool True if authorized to delete the AI Assistant Load Balancer
     */
    public function delete(User $user, AiAssistantLoadBalancer $loadBalancer): bool
    {
        // Must be in same organization
        if ($user->organization_id !== $loadBalancer->organization_id) {
            return false;
        }

        // Only Owner and PBX Admin can delete AI Assistant Load Balancers
        return $user->isOwner() || $user->isPBXAdmin();
    }
}
