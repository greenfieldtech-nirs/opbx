<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for SessionUpdate model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SessionUpdate>
 */
class SessionUpdateFactory extends Factory
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
            'session_id' => fake()->numberBetween(100000, 999999999),
            'session_token' => fake()->uuid(),
            'event_id' => fake()->uuid(),
            'domain_id' => fake()->randomNumber(),
            'domain' => fake()->domainName(),
            'subscriber_id' => fake()->numerify('###'),
            'outgoing_subscriber_id' => null,
            'caller_id' => '+1'.fake()->numerify('##########'),
            'destination' => '+1'.fake()->numerify('##########'),
            'direction' => 'incoming',
            'status' => 'new',
            'session_created_at' => now(),
            'session_modified_at' => now(),
            'call_start_time' => null,
            'start_time' => null,
            'call_answer_time' => null,
            'answer_time' => null,
            'time_limit' => 3600,
            'vapp_server' => null,
            'action' => 'none',
            'reason' => 'normal',
            'last_error' => null,
            'call_ids' => [],
            'profile' => [
                'callerId' => '+1'.fake()->numerify('##########'),
                'destination' => '+1'.fake()->numerify('##########'),
            ],
            'processed_at' => now(),
        ];
    }

    /**
     * Indicate that the session is ringing.
     */
    public function ringing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ringing',
            'start_time' => now(),
        ]);
    }

    /**
     * Indicate that the session is connected/answered.
     */
    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'connected',
            'answer_time' => now(),
        ]);
    }

    /**
     * Indicate that the session is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'session_modified_at' => now(),
        ]);
    }

    /**
     * Set the session to have a specific status.
     */
    public function withStatus(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }

    /**
     * Set the session with duration information.
     */
    public function withDurations(int $durationSeconds): static
    {
        $startTime = now()->subSeconds($durationSeconds);
        $answerTime = $startTime->copy()->addSeconds(max(0, $durationSeconds - 60));
        $endTime = now();

        return $this->state(fn (array $attributes) => [
            'session_created_at' => $startTime,
            'session_modified_at' => $endTime,
            'start_time' => $startTime,
            'answer_time' => $answerTime,
            'status' => 'completed',
        ]);
    }

    /**
     * Set custom profile data.
     */
    public function withProfile(array $profile): static
    {
        return $this->state(fn (array $attributes) => [
            'profile' => $profile,
        ]);
    }
}
