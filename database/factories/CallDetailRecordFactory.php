<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallDetailRecord;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory\u003cCallDetailRecord\u003e
 */
class CallDetailRecordFactory extends Factory
{
    protected $model = CallDetailRecord::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'session_timestamp' => fake()->dateTimeBetween('-30 days', 'now'),
            'session_token' => fake()->uuid(),
            'from' => fake()->e164PhoneNumber(),
            'to' => fake()->e164PhoneNumber(),
            'disposition' => fake()->randomElement(['CONNECTED', 'NO_ANSWER', 'BUSY', 'FAILED']),
            'duration' => fake()->numberBetween(0, 300),
            'billsec' => fake()->numberBetween(0, 300),
            'call_id' => fake()->unique()->uuid(),
            'domain' => fake()->domainName(),
            'raw_cdr' => [],
        ];
    }
}
