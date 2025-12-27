<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Extension;
use App\Models\RingGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RingGroupMember>
 */
class RingGroupMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ring_group_id' => RingGroup::factory(),
            'extension_id' => Extension::factory(),
            'priority' => fake()->numberBetween(1, 10),
        ];
    }

    /**
     * Set a specific priority.
     */
    public function withPriority(int $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
    }
}
