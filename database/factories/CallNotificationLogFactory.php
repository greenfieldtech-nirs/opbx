<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallNotificationLog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<CallNotificationLog>
 */
class CallNotificationLogFactory extends Factory
{
    protected $model = CallNotificationLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'call_session_token' => $this->faker->unique()->uuid,
            'event_id' => $this->faker->uuid,
            'event_type' => $this->faker->randomElement(['new', 'ringing', 'connected', 'completed']),
            'status' => $this->faker->randomElement(['new', 'ringing', 'connected', 'completed']),
            'webhook_url' => 'https://example.com/webhook',
            'request_payload' => ['call_id' => $this->faker->uuid],
            'request_headers' => null,
            'request_body' => null,
            'response_status_code' => 200,
            'response_body' => json_encode(['ok' => true]),
            'response_headers' => null,
            'response_time_ms' => 100,
            'attempt_number' => 1,
            'is_success' => true,
            'error_message' => null,
            'created_at' => now(),
        ];
    }

    /**
     * Indicate that the webhook delivery was successful.
     */
    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_success' => true,
            'response_status_code' => 200,
            'error_message' => null,
        ]);
    }

    /**
     * Indicate that the webhook delivery failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_success' => false,
            'response_status_code' => 500,
            'error_message' => 'Internal Server Error',
        ]);
    }

    /**
     * Set the call session token.
     */
    public function forSession(string $token): static
    {
        return $this->state(fn (array $attributes) => [
            'call_session_token' => $token,
        ]);
    }
}
