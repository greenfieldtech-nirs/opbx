<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformAuditLog>
 */
class PlatformAuditLogFactory extends Factory
{
    protected $model = PlatformAuditLog::class;

    public function definition(): array
    {
        return [
            'platform_manager_user_id' => User::factory(),
            'target_organization_id' => Organization::factory(),
            'action' => $this->faker->randomElement([
                'organization.created',
                'organization.updated',
                'organization.status.updated',
                'user.created',
                'user.updated',
                'user.platform_manager.granted',
                'user.platform_manager.revoked',
            ]),
            'target_entity_type' => $this->faker->randomElement(['Organization', 'User']),
            'target_entity_id' => $this->faker->randomNumber(6),
            'before_state' => null,
            'after_state' => ['name' => $this->faker->company],
            'reason' => $this->faker->optional()->sentence,
            'ip_address' => $this->faker->ipv4,
            'user_agent' => $this->faker->userAgent,
        ];
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn () => [
            'target_organization_id' => $organization->id,
        ]);
    }

    public function byPlatformManager(User $user): self
    {
        return $this->state(fn () => [
            'platform_manager_user_id' => $user->id,
        ]);
    }
}
