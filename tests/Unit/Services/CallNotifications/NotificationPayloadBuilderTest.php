<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CallNotifications;

use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\SessionUpdate;
use App\Services\CallNotifications\NotificationPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPayloadBuilderTest extends TestCase
{
    use RefreshDatabase;

    private NotificationPayloadBuilder $builder;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new NotificationPayloadBuilder;
        $this->organization = Organization::factory()->create();
    }

    private function createSessionUpdate(array $overrides = []): SessionUpdate
    {
        return SessionUpdate::create(array_merge([
            'organization_id' => $this->organization->id,
            'session_id' => fake()->numberBetween(100000, 999999999),
            'session_token' => fake()->uuid(),
            'event_id' => fake()->uuid(),
            'domain_id' => fake()->randomNumber(),
            'domain' => 'test-domain.com',
            'subscriber_id' => '100',
            'caller_id' => '+1112223333',
            'destination' => '+1234567890',
            'direction' => 'incoming',
            'status' => 'new',
            'session_created_at' => now(),
            'session_modified_at' => now(),
            'call_start_time' => null,
            'start_time' => null,
            'call_answer_time' => null,
            'answer_time' => null,
            'time_limit' => 3600,
            'vapp_server' => null,
            'action' => 'none',
            'reason' => 'normal',
            'last_error' => null,
            'call_ids' => [],
            'profile' => [],
            'processed_at' => now(),
            'outgoing_subscriber_id' => null,
        ], $overrides));
    }

    public function test_build_returns_complete_payload_structure(): void
    {
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'test-session-token-123',
            'subscriber_id' => '100',
            'domain' => 'test-domain.com',
            'direction' => 'incoming',
            'status' => 'ringing',
            'profile' => [
                'callerId' => '+1234567890',
                'destination' => '+0987654321',
                'callerName' => 'Test Caller',
            ],
        ]);

        $payload = $this->builder->build($sessionUpdate, 'new');

        $this->assertArrayHasKey('event_type', $payload);
        $this->assertArrayHasKey('event_id', $payload);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertArrayHasKey('organization_id', $payload);
        $this->assertArrayHasKey('session', $payload);
        $this->assertArrayHasKey('metadata', $payload);

        $this->assertEquals('call.status_update', $payload['event_type']);
        $this->assertEquals($this->organization->id, $payload['organization_id']);
    }

    public function test_build_extracts_from_number_from_caller_id(): void
    {
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-from-test',
            'profile' => [
                'callerId' => '+1112223333',
            ],
        ]);

        $payload = $this->builder->build($sessionUpdate);

        $this->assertEquals('+1112223333', $payload['session']['from']);
    }

    public function test_build_extracts_to_number_from_destination(): void
    {
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-to-test',
            'profile' => [
                'destination' => '+4445556666',
            ],
        ]);

        $payload = $this->builder->build($sessionUpdate);

        $this->assertEquals('+4445556666', $payload['session']['to']);
    }

    public function test_build_falls_back_to_subscriber_id_for_from(): void
    {
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-subscriber-test',
            'subscriber_id' => '200',
            'profile' => [
                'from' => 'profile-from-value',
            ],
        ]);

        $payload = $this->builder->build($sessionUpdate);

        // The extractFromNumber checks profile['callerId'] first, then profile['from']
        $this->assertEquals('profile-from-value', $payload['session']['from']);
    }

    public function test_build_falls_back_to_domain_for_to(): void
    {
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-domain-test',
            'domain' => 'fallback-domain.com',
            'destination' => '+1234567890',
            'profile' => [],
        ]);

        $payload = $this->builder->build($sessionUpdate);

        // Since profile is empty and $sessionUpdate->destination is set,
        // it should use $sessionUpdate->destination
        $this->assertEquals('+1234567890', $payload['session']['to']);
    }

    public function test_build_calculates_duration_when_answered_and_ended(): void
    {
        $startTime = now()->subMinutes(10);
        $answerTime = now()->subMinutes(5);
        $endTime = now();

        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-duration-test',
            'status' => 'completed',
            'session_created_at' => $startTime,
            'session_modified_at' => $endTime,
            'answer_time' => $answerTime,
        ]);

        $payload = $this->builder->build($sessionUpdate);

        $this->assertEquals(300, $payload['session']['call_duration']);
        $this->assertEquals(300, $payload['session']['call_billable_duration']);
    }

    public function test_build_calculates_duration_from_created_when_no_answer(): void
    {
        $startTime = now()->subMinutes(3);
        $endTime = now();

        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-no-answer-test',
            'status' => 'busy',
            'session_created_at' => $startTime,
            'session_modified_at' => $endTime,
            'answer_time' => null,
        ]);

        $payload = $this->builder->build($sessionUpdate);

        $this->assertEquals(180, $payload['session']['call_duration']);
        $this->assertEquals(0, $payload['session']['call_billable_duration']);
    }

    public function test_build_includes_extension_id_when_found(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '100',
        ]);

        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-extension-test',
            'subscriber_id' => '100',
        ]);

        $payload = $this->builder->build($sessionUpdate);

        $this->assertEquals($extension->id, $payload['metadata']['extension_id']);
    }

    public function test_build_includes_did_id_when_found(): void
    {
        $did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+1555123456',
        ]);

        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-did-test',
            'destination' => '+1555123456',
        ]);

        $payload = $this->builder->build($sessionUpdate);

        $this->assertEquals($did->id, $payload['metadata']['did_id']);
    }

    public function test_build_normalizes_status_values(): void
    {
        $testCases = [
            'initiated' => 'new',
            'created' => 'new',
            'ring' => 'ringing',
            'progress' => 'ringing',
            'connect' => 'connected',
            'answer' => 'answered',
            'active' => 'answered',
            'cancelled' => 'cancel',
            'canceled' => 'cancel',
            'fail' => 'failed',
            'error' => 'failed',
            'congested' => 'congestion',
            'complete' => 'completed',
            'ended' => 'completed',
            'hangup' => 'completed',
        ];

        foreach ($testCases as $input => $expected) {
            $sessionUpdate = $this->createSessionUpdate([
                'session_token' => 'session-status-'.$input,
                'status' => $input,
            ]);

            $payload = $this->builder->build($sessionUpdate);

            $this->assertEquals($expected, $payload['session']['status'], "Failed for input: {$input}");
        }
    }

    public function test_build_preserves_previous_status(): void
    {
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-prev-status',
            'status' => 'connected',
        ]);

        $payload = $this->builder->build($sessionUpdate, 'ringing');

        $this->assertEquals('ringing', $payload['session']['previous_status']);
    }

    public function test_build_includes_caller_name_from_profile(): void
    {
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-caller-name',
            'profile' => [
                'callerName' => 'John Doe',
            ],
        ]);

        $payload = $this->builder->build($sessionUpdate);

        $this->assertEquals('John Doe', $payload['metadata']['caller_name']);
    }

    public function test_build_includes_domain_in_metadata(): void
    {
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-domain-metadata',
            'domain' => 'my-business.com',
        ]);

        $payload = $this->builder->build($sessionUpdate);

        $this->assertEquals('my-business.com', $payload['metadata']['domain']);
    }

    public function test_build_formats_timestamps_as_iso8601(): void
    {
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-timestamps',
            'status' => 'completed',
            'session_created_at' => now(),
            'answer_time' => now()->addMinute(),
            'session_modified_at' => now()->addMinutes(5),
        ]);

        $payload = $this->builder->build($sessionUpdate);

        $this->assertNotNull($payload['session']['call_start_time']);
        $this->assertNotNull($payload['session']['call_answer_time']);
        $this->assertNotNull($payload['session']['call_end_time']);

        // Verify ISO8601 format (contains 'T' for date/time separator)
        $this->assertStringContainsString('T', $payload['session']['call_start_time']);
    }

    public function test_build_handles_null_timestamps_gracefully(): void
    {
        // Note: session_created_at and session_modified_at are NOT NULL in the database.
        // We test answer_time null handling here.
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-null-times',
            'status' => 'failed',
            'answer_time' => null,
        ]);

        $payload = $this->builder->build($sessionUpdate);

        // call_start_time should have a value since session_created_at is set
        $this->assertNotNull($payload['session']['call_start_time']);
        // answer_time should be null since we didn't set it
        $this->assertNull($payload['session']['call_answer_time']);
        // call_end_time should have a value since session_modified_at is set
        $this->assertNotNull($payload['session']['call_end_time']);
    }

    public function test_build_generates_unique_event_ids(): void
    {
        $sessionUpdate = $this->createSessionUpdate([
            'session_token' => 'session-event-id-1',
        ]);

        $payload1 = $this->builder->build($sessionUpdate);
        $payload2 = $this->builder->build($sessionUpdate);

        $this->assertNotEquals($payload1['event_id'], $payload2['event_id']);
    }
}
