<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QueueCallDisposition;
use App\Models\CallQueue;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QueueCall>
 */
class QueueCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $enteredAt = fake()->dateTimeBetween('-1 day');

        return [
            'organization_id' => Organization::factory(),
            'call_queue_id' => CallQueue::factory(),
            'call_id' => fake()->unique()->bothify('call-########'),
            'session_token' => fake()->unique()->bothify('token-########'),
            'from_number' => fake()->e164PhoneNumber(),
            'to_number' => (string) fake()->numberBetween(1000, 9999),
            'entered_at' => $enteredAt,
            'answered_at' => null,
            'abandoned_at' => null,
            'ended_at' => null,
            'agent_user_id' => null,
            'waiting_seconds' => null,
            'handling_seconds' => null,
            'disposition' => null,
        ];
    }

    /**
     * Mark the queue call as answered with derived timing fields.
     */
    public function answered(User $agent, int $waitSeconds = 30, int $handleSeconds = 120): static
    {
        return $this->state(fn (array $attributes) => [
            'answered_at' => $attributes['entered_at'],
            'ended_at' => $attributes['entered_at'],
            'agent_user_id' => $agent->id,
            'waiting_seconds' => $waitSeconds,
            'handling_seconds' => $handleSeconds,
            'disposition' => QueueCallDisposition::ANSWERED,
        ]);
    }

    /**
     * Mark the queue call as abandoned.
     */
    public function abandoned(int $waitSeconds = 60): static
    {
        return $this->state(fn (array $attributes) => [
            'abandoned_at' => $attributes['entered_at'],
            'waiting_seconds' => $waitSeconds,
            'disposition' => QueueCallDisposition::ABANDONED,
        ]);
    }
}
