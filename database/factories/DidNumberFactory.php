<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BusinessHoursSchedule;
use App\Models\ConferenceRoom;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for DID Number (Phone Number) model.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DidNumber>
 */
class DidNumberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Generate a random E.164 phone number
        $phoneNumber = '+1' . fake()->unique()->numerify('##########');

        return [
            'organization_id' => Organization::factory(),
            'phone_number' => $phoneNumber,
            'friendly_name' => fake()->optional()->words(3, true),
            'routing_type' => 'extension',
            'routing_config' => [
                'extension_id' => Extension::factory(),
            ],
            'status' => 'active',
            'cloudonix_config' => null,
        ];
    }

    /**
     * Indicate that the phone number routes to an extension.
     */
    public function routeToExtension(?Extension $extension = null): static
    {
        return $this->state(function (array $attributes) use ($extension) {
            $extensionId = $extension?->id ?? Extension::factory()->create()->id;

            return [
                'routing_type' => 'extension',
                'routing_config' => [
                    'extension_id' => $extensionId,
                ],
            ];
        });
    }

    /**
     * Indicate that the phone number routes to a ring group.
     */
    public function routeToRingGroup(?RingGroup $ringGroup = null): static
    {
        return $this->state(function (array $attributes) use ($ringGroup) {
            $ringGroupId = $ringGroup?->id ?? RingGroup::factory()->create()->id;

            return [
                'routing_type' => 'ring_group',
                'routing_config' => [
                    'ring_group_id' => $ringGroupId,
                ],
            ];
        });
    }

    /**
     * Indicate that the phone number routes to business hours.
     */
    public function routeToBusinessHours(?BusinessHoursSchedule $schedule = null): static
    {
        return $this->state(function (array $attributes) use ($schedule) {
            $scheduleId = $schedule?->id ?? BusinessHoursSchedule::factory()->create()->id;

            return [
                'routing_type' => 'business_hours',
                'routing_config' => [
                    'business_hours_schedule_id' => $scheduleId,
                ],
            ];
        });
    }

    /**
     * Indicate that the phone number routes to a conference room.
     */
    public function routeToConferenceRoom(?ConferenceRoom $conferenceRoom = null): static
    {
        return $this->state(function (array $attributes) use ($conferenceRoom) {
            $conferenceRoomId = $conferenceRoom?->id;

            if ($conferenceRoomId === null) {
                // Create a basic conference room if none provided
                $conferenceRoomId = ConferenceRoom::factory()->create()->id;
            }

            return [
                'routing_type' => 'conference_room',
                'routing_config' => [
                    'conference_room_id' => $conferenceRoomId,
                ],
            ];
        });
    }

    /**
     * Indicate that the phone number is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }

    /**
     * Indicate that the phone number has a friendly name.
     */
    public function withFriendlyName(string $name): static
    {
        return $this->state(fn (array $attributes) => [
            'friendly_name' => $name,
        ]);
    }

    /**
     * Indicate that the phone number has Cloudonix configuration.
     */
    public function withCloudonixConfig(array $config): static
    {
        return $this->state(fn (array $attributes) => [
            'cloudonix_config' => $config,
        ]);
    }
}
