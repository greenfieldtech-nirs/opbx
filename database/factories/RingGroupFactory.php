<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RingGroupFallbackAction;
use App\Enums\RingGroupStatus;
use App\Enums\RingGroupStrategy;
use App\Models\Extension;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RingGroup>
 */
class RingGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->words(3, true) . ' Ring Group',
            'description' => fake()->sentence(),
            'strategy' => RingGroupStrategy::SIMULTANEOUS,
            'timeout' => 30,
            'ring_turns' => 2,
            'fallback_action' => RingGroupFallbackAction::HANGUP,
            'fallback_extension_id' => null,
            'status' => RingGroupStatus::ACTIVE,
        ];
    }

    /**
     * Indicate that the ring group is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RingGroupStatus::INACTIVE,
        ]);
    }

    /**
     * Set the ring group strategy to round robin.
     */
    public function roundRobin(): static
    {
        return $this->state(fn (array $attributes) => [
            'strategy' => RingGroupStrategy::ROUND_ROBIN,
        ]);
    }

    /**
     * Set the ring group strategy to sequential.
     */
    public function sequential(): static
    {
        return $this->state(fn (array $attributes) => [
            'strategy' => RingGroupStrategy::SEQUENTIAL,
        ]);
    }

    /**
     * Set fallback action to extension with optional extension ID.
     */
    public function withFallbackExtension(?Extension $extension = null): static
    {
        return $this->state(fn (array $attributes) => [
            'fallback_action' => RingGroupFallbackAction::EXTENSION,
            'fallback_extension_id' => $extension?->id ?? Extension::factory(),
        ]);
    }
}
