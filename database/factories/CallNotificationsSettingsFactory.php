<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallNotificationsSettings;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for CallNotificationsSettings model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CallNotificationsSettings>
 */
class CallNotificationsSettingsFactory extends Factory
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
            'webhook_url' => 'https://example.com/webhook',
            'auth_method' => 'hmac_sha256',
            'auth_secret' => $this->faker->sha256(),
            'retry_attempts' => 3,
            'retry_backoff_seconds' => 60,
            'request_timeout_seconds' => 30,
            'enabled_events' => ['new', 'ringing', 'connected', 'completed'],
            'rate_limit_per_minute' => 500,
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the webhook is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Use Bearer token authentication.
     */
    public function withBearerToken(string $token): static
    {
        return $this->state(fn (array $attributes) => [
            'auth_method' => 'bearer',
            'auth_secret' => $token,
        ]);
    }

    /**
     * Use Basic authentication.
     */
    public function withBasicAuth(string $username, string $password): static
    {
        return $this->state(fn (array $attributes) => [
            'auth_method' => 'basic',
            'auth_username' => $username,
            'auth_secret' => $password,
        ]);
    }

    /**
     * Use HMAC authentication.
     */
    public function withHmacAuth(string $secret): static
    {
        return $this->state(fn (array $attributes) => [
            'auth_method' => 'hmac',
            'auth_secret' => $secret,
        ]);
    }

    /**
     * Set custom retry settings.
     */
    public function withRetrySettings(int $attempts, int $backoffSeconds): static
    {
        return $this->state(fn (array $attributes) => [
            'retry_attempts' => $attempts,
            'retry_backoff_seconds' => $backoffSeconds,
        ]);
    }

    /**
     * Set custom rate limit.
     */
    public function withRateLimit(int $perMinute): static
    {
        return $this->state(fn (array $attributes) => [
            'rate_limit_per_minute' => $perMinute,
        ]);
    }
}
