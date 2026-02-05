<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for AI Assistant model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiAssistant>
 */
class AiAssistantFactory extends Factory
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
            'name' => fake()->words(3, true).' Bot',
            'description' => fake()->optional()->sentence(),
            'status' => UserStatus::ACTIVE,
            'provider' => 'vapi',
            'protocol' => 'sip',
            'configuration' => [
                'phone_number' => fake()->e164PhoneNumber(),
            ],
            'created_by' => null,
            'updated_by' => null,
        ];
    }

    /**
     * Indicate that the AI assistant is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::INACTIVE,
        ]);
    }

    /**
     * Indicate that the AI assistant uses SIP protocol.
     */
    public function sip(string $provider = 'vapi'): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => $provider,
            'protocol' => 'sip',
            'configuration' => [
                'phone_number' => fake()->e164PhoneNumber(),
            ],
        ]);
    }

    /**
     * Indicate that the AI assistant uses WebSocket protocol.
     */
    public function websocket(string $provider = 'deepdub'): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => $provider,
            'protocol' => 'websocket',
            'configuration' => [
                'bot_id' => fake()->uuid(),
                'auth_token' => fake()->sha256(),
            ],
        ]);
    }

    /**
     * Set the created_by and updated_by user.
     */
    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
