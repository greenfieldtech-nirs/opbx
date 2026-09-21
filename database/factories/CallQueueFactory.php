<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CallQueueStatus;
use App\Enums\CallQueueStrategy;
use App\Enums\RingGroupFallbackAction;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CallQueue>
 */
class CallQueueFactory extends Factory
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
            'name' => fake()->words(3, true).' Queue',
            'description' => fake()->sentence(),
            'strategy' => CallQueueStrategy::RING_ALL,
            'agent_ring_timeout' => 20,
            'max_wait_seconds' => 300,
            'wrap_up_seconds' => 15,
            'moh_recording_id' => null,
            'fallback_action' => RingGroupFallbackAction::HANGUP,
            'fallback_extension_id' => null,
            'fallback_ring_group_id' => null,
            'fallback_ivr_menu_id' => null,
            'fallback_ai_assistant_id' => null,
            'fallback_ai_load_balancer_id' => null,
            'status' => CallQueueStatus::ACTIVE,
        ];
    }

    /**
     * Indicate that the queue is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CallQueueStatus::INACTIVE,
        ]);
    }
}
