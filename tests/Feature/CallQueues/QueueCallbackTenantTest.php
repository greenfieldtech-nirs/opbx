<?php

declare(strict_types=1);

namespace Tests\Feature\CallQueues;

use App\Models\CallQueue;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\QueueCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QueueCallbackTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_a_credentials_cannot_drive_org_b_queue(): void
    {
        $orgA = Organization::factory()->create(['status' => 'active']);
        $orgB = Organization::factory()->create(['status' => 'active']);
        CloudonixSettings::factory()->create([
            'organization_id' => $orgA->id,
            'domain_name' => 'a.example.com',
            'domain_requests_api_key' => 'tokenA',
        ]);
        $queueB = CallQueue::factory()->create(['organization_id' => $orgB->id]);
        $callB = QueueCall::factory()->create([
            'organization_id' => $orgB->id,
            'call_queue_id' => $queueB->id,
            'call_id' => 'call-b-1',
        ]);

        $payload = [
            'CallSid' => 'call-b-1',
            'CallStatus' => 'failed',
            'Domain' => 'a.example.com',
            'To' => '8000',
            'session_data' => json_encode([
                'call_queue_id' => $queueB->id,
                'call_id' => 'call-b-1',
                'organization_id' => $orgB->id,
            ]),
        ];

        foreach (['queue-poll', 'queue-dial-callback'] as $route) {
            $content = (string) $this->postJson("/api/callbacks/voice/{$route}", $payload, [
                'Authorization' => 'Bearer tokenA',
            ])->assertOk()->getContent();

            $this->assertStringNotContainsString('<Dial', $content);
        }

        $fresh = $callB->fresh();
        $this->assertNull($fresh->disposition);
        $this->assertNull($fresh->answered_at);
    }
}
