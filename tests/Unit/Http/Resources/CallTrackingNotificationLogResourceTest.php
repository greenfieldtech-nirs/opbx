<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\CallTrackingNotificationLogResource;
use App\Models\CallTrackingNotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallTrackingNotificationLogResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_resource_omits_request_and_response_payloads(): void
    {
        $log = CallTrackingNotificationLog::factory()->create([
            'request_payload' => ['secret' => 'value'],
            'request_headers' => ['Authorization' => 'Bearer secret'],
            'response_body' => 'secret response',
            'response_headers' => ['X-Token' => 'secret'],
        ]);

        $resource = (new CallTrackingNotificationLogResource($log))->toArray(request());

        $this->assertArrayNotHasKey('request_payload', $resource);
        $this->assertArrayNotHasKey('request_headers', $resource);
        $this->assertArrayNotHasKey('response_body', $resource);
        $this->assertArrayNotHasKey('response_headers', $resource);
    }

    public function test_resource_includes_expected_fields(): void
    {
        $log = CallTrackingNotificationLog::factory()->create([
            'event_type' => 'call.converted',
            'is_success' => true,
            'response_status_code' => 200,
        ]);

        $resource = (new CallTrackingNotificationLogResource($log))->toArray(request());

        $this->assertSame($log->id, $resource['id']);
        $this->assertSame('call.converted', $resource['event_type']);
        $this->assertTrue($resource['is_success']);
        $this->assertSame(200, $resource['response_status_code']);
    }
}
