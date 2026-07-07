<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CallTrackingCampaign;
use App\Models\CallTrackingNotificationLog;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class CallTrackingNotificationLogFactory extends Factory
{
    protected $model = CallTrackingNotificationLog::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'call_tracking_campaign_id' => CallTrackingCampaign::factory(),
            'call_id' => $this->faker->uuid(),
            'event_id' => $this->faker->uuid(),
            'event_type' => 'call.received',
            'webhook_url' => $this->faker->url(),
            'request_payload' => [],
            'request_headers' => [],
            'response_body' => null,
            'response_headers' => [],
            'response_status_code' => 200,
            'response_time_ms' => 100,
            'is_success' => true,
            'attempt_number' => 1,
            'error_message' => null,
        ];
    }
}
