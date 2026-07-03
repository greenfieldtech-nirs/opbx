<?php

namespace Database\Factories;

use App\Enums\DestinationStatus;
use App\Models\AutoDialerDestination;
use App\Models\AutoDialerList;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<AutoDialerDestination>
 */
class AutoDialerDestinationFactory extends Factory
{
    protected $model = AutoDialerDestination::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'list_id' => AutoDialerList::factory(),
            'phone_number' => '+1'.$this->faker->numberBetween(2000000000, 9999999999),
            'name' => $this->faker->optional()->name,
            'status' => DestinationStatus::PENDING,
            'dial_attempts' => 0,
            'last_session_token' => null,
            'last_call_id' => null,
            'last_dialed_at' => null,
            'next_retry_at' => null,
            'last_disposition' => null,
            'duration' => 0,
            'billsec' => 0,
            'total_duration' => 0,
            'last_cdr_id' => null,
            'last_error' => null,
        ];
    }

    /**
     * Set the destination status to pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DestinationStatus::PENDING,
            'dial_attempts' => 0,
        ]);
    }

    /**
     * Set the destination status to dialing.
     */
    public function dialing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DestinationStatus::DIALING,
            'last_dialed_at' => now(),
        ]);
    }

    /**
     * Set the destination status to completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DestinationStatus::COMPLETED,
            'last_disposition' => 'answered',
            'dial_attempts' => 1,
        ]);
    }

    /**
     * Set the destination status to failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DestinationStatus::FAILED,
            'last_disposition' => 'busy',
            'dial_attempts' => 1,
        ]);
    }

    /**
     * Set the destination as ready for retry.
     */
    public function readyForRetry(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DestinationStatus::FAILED,
            'last_disposition' => 'busy',
            'dial_attempts' => 1,
            'next_retry_at' => now()->subMinute(),
        ]);
    }

    /**
     * Set the destination as not yet ready for retry.
     */
    public function notReadyForRetry(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DestinationStatus::FAILED,
            'last_disposition' => 'no-answer',
            'dial_attempts' => 1,
            'next_retry_at' => now()->addHour(),
        ]);
    }
}
