<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CallTracking;

use App\Enums\CallTrackingEventType;
use App\Models\CallTrackingSession;
use App\Scopes\OrganizationScope;
use App\Services\CallTracking\NotificationPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    private NotificationPayloadBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new NotificationPayloadBuilder;
    }

    public function test_builds_notification_payload_from_session(): void
    {
        $session = OrganizationScope::bypass(fn () => CallTrackingSession::factory()->create([
            'caller_number' => '+15551112222',
            'called_number' => '+15553334444',
            'source' => 'google',
            'medium' => 'cpc',
            'duration' => 120,
            'billsec' => 115,
            'is_answered' => true,
            'is_converted' => true,
            'conversion_value' => 99.99,
        ]));

        $eventType = CallTrackingEventType::CALL_CONVERTED->value;
        $eventId = 'evt_'.uniqid();

        $payload = $this->builder->build($session, $eventType, $eventId);

        $this->assertSame($eventType, $payload['event']);
        $this->assertSame($eventId, $payload['event_id']);
        $this->assertSame($session->organization_id, $payload['organization_id']);
        $this->assertSame($session->call_tracking_campaign_id, $payload['campaign']['id']);
        $this->assertSame($session->campaign_name, $payload['campaign']['name']);
        $this->assertSame($session->called_number, $payload['tracking_number']);
        $this->assertSame($session->caller_number, $payload['caller_number']);
        $this->assertSame($session->source, $payload['source']);
        $this->assertSame($session->medium, $payload['medium']);
        $this->assertSame($session->duration, $payload['duration']);
        $this->assertSame($session->billsec, $payload['billsec']);
        $this->assertSame($session->is_answered, $payload['is_answered']);
        $this->assertSame($session->is_converted, $payload['is_converted']);
        $this->assertEquals($session->conversion_value, $payload['conversion_value']);

        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $payload['timestamp']);
    }
}
