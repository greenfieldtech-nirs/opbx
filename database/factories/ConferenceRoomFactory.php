<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\UserStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for Conference Room model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ConferenceRoom>
 */
class ConferenceRoomFactory extends Factory
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
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'max_participants' => 25,
            'status' => UserStatus::ACTIVE,
            'pin' => null,
            'pin_required' => false,
            'host_pin' => null,
            'recording_enabled' => false,
            'recording_auto_start' => false,
            'recording_webhook_url' => null,
            'wait_for_host' => false,
            'mute_on_entry' => false,
            'announce_join_leave' => false,
            'music_on_hold' => false,
            'talk_detection_enabled' => false,
            'talk_detection_webhook_url' => null,
        ];
    }

    /**
     * Indicate that the conference room is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => UserStatus::INACTIVE,
        ]);
    }

    /**
     * Indicate that the conference room requires a PIN.
     */
    public function withPin(string $pin = '1234'): static
    {
        return $this->state(fn (array $attributes) => [
            'pin' => $pin,
            'pin_required' => true,
        ]);
    }

    /**
     * Indicate that the conference room has recording enabled.
     */
    public function withRecording(bool $autoStart = false): static
    {
        return $this->state(fn (array $attributes) => [
            'recording_enabled' => true,
            'recording_auto_start' => $autoStart,
        ]);
    }
}
