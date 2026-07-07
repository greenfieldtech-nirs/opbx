<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CallTrackingCampaignStatus;
use App\Enums\CallTrackingDestinationType;
use App\Models\CallTrackingCampaign;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallTrackingCampaign>
 */
class CallTrackingCampaignFactory extends Factory
{
    protected $model = CallTrackingCampaign::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->company.' Call Tracking Campaign',
            'source' => $this->faker->optional()->word,
            'medium' => $this->faker->optional()->word,
            'description' => $this->faker->optional()->sentence,
            'status' => CallTrackingCampaignStatus::ACTIVE,
            'destination_type' => CallTrackingDestinationType::FORWARD,
            'destination_config' => [
                'forward_to' => '+1'.$this->faker->numberBetween(2000000000, 9999999999),
            ],
            'conversion_rule' => [
                'min_answered_duration_seconds' => 60,
                'requires_answered_disposition' => true,
            ],
            'google_ads_upload_enabled' => false,
            'meta_upload_enabled' => false,
        ];
    }

    /**
     * Indicate that the campaign forwards to an external number.
     */
    public function forwardTo(string $number): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => CallTrackingDestinationType::FORWARD,
            'destination_config' => [
                'forward_to' => $number,
            ],
        ]);
    }

    /**
     * Indicate that the campaign routes to an extension.
     */
    public function toExtension(int $extensionId): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => CallTrackingDestinationType::EXTENSION,
            'destination_config' => [
                'extension_id' => $extensionId,
            ],
        ]);
    }

    /**
     * Indicate that the campaign routes to a ring group.
     */
    public function toRingGroup(int $ringGroupId): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => CallTrackingDestinationType::RING_GROUP,
            'destination_config' => [
                'ring_group_id' => $ringGroupId,
            ],
        ]);
    }

    /**
     * Indicate that the campaign routes to a business hours schedule.
     */
    public function toBusinessHours(int $scheduleId): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => CallTrackingDestinationType::BUSINESS_HOURS,
            'destination_config' => [
                'business_hours_schedule_id' => $scheduleId,
            ],
        ]);
    }

    /**
     * Indicate that the campaign routes to a conference room.
     */
    public function toConferenceRoom(int $conferenceRoomId): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => CallTrackingDestinationType::CONFERENCE_ROOM,
            'destination_config' => [
                'conference_room_id' => $conferenceRoomId,
            ],
        ]);
    }

    /**
     * Indicate that the campaign routes to an IVR menu.
     */
    public function toIvrMenu(int $ivrMenuId): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => CallTrackingDestinationType::IVR_MENU,
            'destination_config' => [
                'ivr_menu_id' => $ivrMenuId,
            ],
        ]);
    }

    /**
     * Indicate that the campaign routes to an AI assistant.
     */
    public function toAiAssistant(int $aiAssistantId): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => CallTrackingDestinationType::AI_ASSISTANT,
            'destination_config' => [
                'ai_assistant_id' => $aiAssistantId,
            ],
        ]);
    }

    /**
     * Indicate that the campaign routes to an AI assistant load balancer.
     */
    public function toAiLoadBalancer(int $loadBalancerId): static
    {
        return $this->state(fn (array $attributes) => [
            'destination_type' => CallTrackingDestinationType::AI_LOAD_BALANCER,
            'destination_config' => [
                'ai_load_balancer_id' => $loadBalancerId,
            ],
        ]);
    }

    /**
     * Indicate that the campaign is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CallTrackingCampaignStatus::INACTIVE,
        ]);
    }
}
