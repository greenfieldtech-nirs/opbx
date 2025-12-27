<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Extension>
 */
class ExtensionFactory extends Factory
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
            'user_id' => null, // Extension can be unassigned
            'extension_number' => fake()->unique()->numberBetween(1000, 9999),
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
            'voicemail_enabled' => false,
            'configuration' => [
                'sip_uri' => 'sip:' . fake()->unique()->numberBetween(1000, 9999) . '@example.com',
            ],
        ];
    }

    /**
     * Indicate that the extension is assigned to a user.
     */
    public function withUser(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user?->id ?? User::factory(),
        ]);
    }

    /**
     * Indicate that the extension is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::INACTIVE,
        ]);
    }

    /**
     * Indicate that the extension has voicemail enabled.
     */
    public function withVoicemail(): static
    {
        return $this->state(fn (array $attributes) => [
            'voicemail_enabled' => true,
        ]);
    }

    /**
     * Indicate that the extension has no configuration.
     */
    public function withoutConfiguration(): static
    {
        return $this->state(fn (array $attributes) => [
            'configuration' => null,
        ]);
    }

    /**
     * Indicate that the extension has empty configuration.
     */
    public function withEmptyConfiguration(): static
    {
        return $this->state(fn (array $attributes) => [
            'configuration' => [],
        ]);
    }
}
