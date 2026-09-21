<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallQueue;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\CallQueueAgent>
 */
class CallQueueAgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'call_queue_id' => CallQueue::factory(),
            'user_id' => User::factory(),
        ];
    }
}
