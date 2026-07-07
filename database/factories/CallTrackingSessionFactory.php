<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNumber;
use App\Models\CallTrackingSession;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CallTrackingSession>
 */
class CallTrackingSessionFactory extends Factory
{
    protected $model = CallTrackingSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = CallTrackingNumber::factory()->create();
        $campaign = $number->campaign;
        $did = $number->did;
        $startedAt = Carbon::instance($this->faker->dateTimeBetween('-30 days', 'now'));
        $duration = $this->faker->numberBetween(0, 300);
        $billsec = $duration > 0 ? $this->faker->numberBetween(0, $duration) : 0;
        $disposition = $billsec > 0 ? 'CONNECTED' : 'NO ANSWER';

        return [
            'organization_id' => $number->organization_id,
            'call_tracking_campaign_id' => $campaign->id,
            'call_tracking_number_id' => $number->id,
            'did_number_id' => $did->id,
            'call_id' => $this->faker->uuid,
            'session_id' => $this->faker->optional()->uuid,
            'caller_number' => '+1'.$this->faker->numberBetween(2000000000, 9999999999),
            'caller_country' => $this->faker->optional()->countryCode,
            'called_number' => $did->phone_number,
            'source' => $campaign->source ?? $this->faker->word,
            'medium' => $campaign->medium ?? $this->faker->word,
            'campaign_name' => $campaign->name,
            'disposition' => $disposition,
            'duration' => $duration,
            'billsec' => $billsec,
            'is_answered' => $billsec > 0,
            'is_converted' => false,
            'conversion_value' => null,
            'started_at' => $startedAt,
            'answered_at' => $billsec > 0 ? $startedAt->copy()->addSeconds($this->faker->numberBetween(1, 10)) : null,
            'ended_at' => $startedAt->copy()->addSeconds($duration),
            'raw_cdr' => [
                'call_id' => $this->faker->uuid,
                'from' => '+1'.$this->faker->numberBetween(2000000000, 9999999999),
                'to' => $did->phone_number,
                'disposition' => $disposition,
                'duration' => $duration,
                'billsec' => $billsec,
            ],
        ];
    }

    /**
     * Indicate that the session is converted.
     */
    public function converted(): static
    {
        return $this->state(fn (array $attributes) => [
            'disposition' => 'CONNECTED',
            'is_answered' => true,
            'is_converted' => true,
            'conversion_value' => $this->faker->randomFloat(4, 10, 1000),
        ]);
    }

    /**
     * Assign the session to a specific campaign and number.
     */
    public function forCampaignAndNumber(CallTrackingCampaign $campaign, CallTrackingNumber $number): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $campaign->organization_id,
            'call_tracking_campaign_id' => $campaign->id,
            'call_tracking_number_id' => $number->id,
            'did_number_id' => $number->did_number_id,
            'called_number' => $number->did->phone_number,
            'campaign_name' => $campaign->name,
            'source' => $campaign->source,
            'medium' => $campaign->medium,
        ]);
    }

    /**
     * Set the raw CDR payload.
     */
    public function withRawCdr(array $cdr): static
    {
        return $this->state(fn (array $attributes) => [
            'raw_cdr' => $cdr,
        ]);
    }
}
