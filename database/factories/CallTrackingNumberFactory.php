<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CallTrackingCampaignStatus;
use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\DidNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallTrackingNumber>
 */
class CallTrackingNumberFactory extends Factory
{
    protected $model = CallTrackingNumber::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $campaign = CallTrackingCampaign::factory()->create();

        return [
            'organization_id' => $campaign->organization_id,
            'call_tracking_campaign_id' => $campaign->id,
            'did_number_id' => DidNumber::factory()->state([
                'organization_id' => $campaign->organization_id,
            ]),
            'friendly_name' => $this->faker->optional()->words(3, true),
            'status' => CallTrackingCampaignStatus::ACTIVE,
        ];
    }

    /**
     * Indicate that the tracking number is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CallTrackingCampaignStatus::INACTIVE,
        ]);
    }

    /**
     * Assign the tracking number to a specific campaign.
     */
    public function forCampaign(CallTrackingCampaign $campaign): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $campaign->organization_id,
            'call_tracking_campaign_id' => $campaign->id,
        ]);
    }

    /**
     * Assign the tracking number to a specific DID.
     */
    public function forDid(DidNumber $did): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $did->organization_id,
            'did_number_id' => $did->id,
        ]);
    }
}
