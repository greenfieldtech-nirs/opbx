<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Enums\RoutingDestinationType;
use App\Models\AutoDialerCampaign;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutoDialerCampaign>
 */
class AutoDialerCampaignFactory extends Factory
{
    protected $model = AutoDialerCampaign::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->company.' Campaign',
            'description' => $this->faker->sentence,
            'status' => CampaignStatus::DRAFT,
            'auto_start' => false,
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
            'routing_destination_id' => null,
            'dial_timeout' => 60,
            'destination_connect' => 'connected',
            'caller_id' => '+1'.$this->faker->numberBetween(2000000000, 9999999999),
            'max_dial_attempts' => 3,
            'calls_per_second' => 1,
            'days_active' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'start_time' => 9,
            'end_time' => 17,
            'start_date' => now(),
            'end_date' => now()->addDays(30),
            'timezone' => 'UTC',
            'time_limit' => 3600,
            'record_calls' => false,
            'amd_enabled' => false,
            'amd_mode' => null,
            'amd_timeout' => 30,
            'amd_speech_threshold' => 1500,
            'amd_speech_end_threshold' => 2500,
            'amd_silence_timeout' => 3500,
            'total_destinations' => 0,
            'completed_calls' => 0,
            'failed_calls' => 0,
            'pending_calls' => 0,
        ];
    }

    /**
     * Indicate that the campaign is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::ACTIVE,
            'started_at' => now(),
        ]);
    }

    /**
     * Indicate that the campaign is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CampaignStatus::COMPLETED,
            'completed_at' => now(),
        ]);
    }
}
