<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiCallAttemptSource;
use App\Enums\AiCallAttemptStatus;
use App\Models\AiCallAttempt;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCallAttempt>
 */
class AiCallAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'ai_assistant_id' => null,
            'ai_assistant_load_balancer_id' => null,
            'call_id' => fake()->unique()->bothify('token-########'),
            'source' => AiCallAttemptSource::ROUTING,
            'status' => AiCallAttemptStatus::IN_PROGRESS,
            'started_at' => now()->subMinutes(5),
        ];
    }

    public function answered(int $billsec = 120): static
    {
        return $this->state(fn () => [
            'status' => AiCallAttemptStatus::ANSWERED,
            'ended_at' => now(),
            'duration' => $billsec + 5,
            'billsec' => $billsec,
        ]);
    }

    public function failed(string $reason = 'failed'): static
    {
        return $this->state(fn () => [
            'status' => AiCallAttemptStatus::FAILED,
            'failure_reason' => $reason,
            'ended_at' => now(),
            'duration' => 0,
            'billsec' => 0,
        ]);
    }
}
