<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiAssistant;
use App\Models\AiAssistantLoadBalancer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiAssistantLoadBalancerMember>
 */
class AiAssistantLoadBalancerMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'load_balancer_id' => AiAssistantLoadBalancer::factory(),
            'ai_assistant_id' => AiAssistant::factory(),
            'priority' => 0,
            'weight' => 100,
            'position' => 0,
            'status' => 'active',
        ];
    }

    /**
     * Indicate that the member is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Set the priority for priority-based strategy.
     */
    public function withPriority(int $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority,
        ]);
    }

    /**
     * Set the weight for percentage-based strategy.
     */
    public function withWeight(int $weight): static
    {
        return $this->state(fn (array $attributes) => [
            'weight' => $weight,
        ]);
    }

    /**
     * Set the position for round robin strategy.
     */
    public function withPosition(int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'position' => $position,
        ]);
    }
}
