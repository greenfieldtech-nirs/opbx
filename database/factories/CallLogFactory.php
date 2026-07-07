<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CallStatus;
use App\Models\CallLog;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<CallLog>
 */
class CallLogFactory extends Factory
{
    protected $model = CallLog::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'call_id' => $this->faker->unique()->uuid,
            'direction' => $this->faker->randomElement(['inbound', 'outbound']),
            'from_number' => '+1'.$this->faker->numberBetween(2000000000, 9999999999),
            'to_number' => '+1'.$this->faker->numberBetween(2000000000, 9999999999),
            'did_id' => null,
            'extension_id' => null,
            'ring_group_id' => null,
            'status' => CallStatus::INITIATED,
            'initiated_at' => now(),
            'answered_at' => null,
            'ended_at' => null,
            'duration' => null,
            'recording_url' => null,
            'cloudonix_cdr' => null,
        ];
    }

    /**
     * Indicate that the call is answered.
     */
    public function answered(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CallStatus::ANSWERED,
            'answered_at' => now(),
        ]);
    }

    /**
     * Indicate that the call is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CallStatus::COMPLETED,
            'answered_at' => $attributes['answered_at'] ?? now()->subSeconds(30),
            'ended_at' => now(),
            'duration' => 30,
        ]);
    }

    /**
     * Associate the call log with a DID number.
     */
    public function forDidNumber(DidNumber $didNumber): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $didNumber->organization_id,
            'did_id' => $didNumber->id,
        ]);
    }

    /**
     * Associate the call log with an extension.
     */
    public function forExtension(Extension $extension): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $extension->organization_id,
            'extension_id' => $extension->id,
        ]);
    }

    /**
     * Associate the call log with a ring group.
     */
    public function forRingGroup(RingGroup $ringGroup): static
    {
        return $this->state(fn (array $attributes) => [
            'organization_id' => $ringGroup->organization_id,
            'ring_group_id' => $ringGroup->id,
        ]);
    }
}
