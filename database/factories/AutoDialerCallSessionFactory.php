<?php

namespace Database\Factories;

use App\Models\AutoDialerCallSession;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<AutoDialerCallSession>
 */
class AutoDialerCallSessionFactory extends Factory
{
    protected $model = AutoDialerCallSession::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'campaign_id' => AutoDialerCampaign::factory(),
            'destination_id' => AutoDialerDestination::factory(),
            'call_id' => $this->faker->uuid,
            'phone_number' => '+1'.$this->faker->numberBetween(2000000000, 9999999999),
            'worker_id' => 'worker-'.$this->faker->randomNumber(3),
            'status' => 'dialing',
            'disposition' => null,
            'duration' => 0,
            'billsec' => 0,
            'recording_url' => null,
            'started_at' => now(),
            'ended_at' => null,
            'answered_at' => null,
        ];
    }

    /**
     * Set the session status to dialing.
     */
    public function dialing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'dialing',
            'started_at' => now(),
        ]);
    }

    /**
     * Set the session status to connected.
     */
    public function connected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'connected',
            'answered_at' => now(),
        ]);
    }

    /**
     * Set the session status to completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'disposition' => 'answered',
            'duration' => $this->faker->numberBetween(30, 300),
            'billsec' => $this->faker->numberBetween(25, 295),
            'ended_at' => now(),
            'answered_at' => now()->subSeconds($this->faker->numberBetween(30, 300)),
        ]);
    }

    /**
     * Set the session status to failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'disposition' => $this->faker->randomElement(['busy', 'no-answer', 'failed', 'cancelled', 'congestion']),
            'ended_at' => now(),
        ]);
    }

    /**
     * Set the session as having a recording.
     */
    public function withRecording(): static
    {
        return $this->state(fn (array $attributes) => [
            'recording_url' => 'https://recordings.example.com/'.$this->faker->uuid.'.mp3',
        ]);
    }
}
