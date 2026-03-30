<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\AmdMode;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\Organization;
use App\Services\AutoDialer\AutoDialerCloudonixService;
use App\Services\CloudonixClient\CloudonixClient;
use Mockery;
use Tests\TestCase;

/**
 * Auto Dialer Cloudonix Service Tests
 *
 * Tests the Cloudonix integration layer for the auto dialer.
 * These tests verify the service correctly formats API requests
 * and handles responses.
 */
class AutoDialerCloudonixServiceTest extends TestCase
{
    private AutoDialerCloudonixService $service;

    private $mockClient;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(CloudonixClient::class);
        $this->service = new AutoDialerCloudonixService($this->mockClient);

        $this->organization = Organization::factory()->make(['id' => 1]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Create a mock campaign with specified attributes.
     */
    private function createCampaign(array $overrides = []): AutoDialerCampaign
    {
        $defaults = [
            'id' => 1,
            'organization_id' => $this->organization->id,
            'name' => 'Test Campaign',
            'caller_id' => '+15551234567',
            'routing_destination_type' => 'ai_assistant',
            'routing_destination_id' => 5,
            'dial_timeout' => 30,
            'time_limit' => 3600,
            'record_calls' => true,
            'amd_enabled' => false,
            'amd_mode' => null,
            'amd_timeout' => 30,
            'amd_speech_threshold' => 1500,
            'amd_speech_end_threshold' => 2500,
            'amd_silence_timeout' => 3500,
        ];

        $campaign = new AutoDialerCampaign(array_merge($defaults, $overrides));
        $campaign->id = $defaults['id'];

        return $campaign;
    }

    /**
     * Create a mock destination.
     */
    private function createDestination(array $overrides = []): AutoDialerDestination
    {
        $defaults = [
            'id' => 123,
            'organization_id' => $this->organization->id,
            'list_id' => 1,
            'phone_number' => '+12025551234',
            'status' => 'pending',
        ];

        $destination = new AutoDialerDestination(array_merge($defaults, $overrides));
        $destination->id = $defaults['id'];

        return $destination;
    }

    public function test_initiate_call_with_ai_assistant_routing(): void
    {
        $campaign = $this->createCampaign([
            'routing_destination_type' => 'ai_assistant',
            'routing_destination_id' => 5,
        ]);
        $destination = $this->createDestination();
        $webhookUrl = 'https://example.com/webhooks/cloudonix';

        $this->mockClient
            ->shouldReceive('initiateCall')
            ->once()
            ->with(
                '+15551234567', // from
                '+12025551234', // to
                'default', // trunk
                Mockery::on(function ($options) {
                    return $options['timeout'] === 30
                        && $options['timeLimit'] === 3600
                        && $options['recording'] === true
                        && $options['application'] === 'ai:5';
                })
            )
            ->andReturn([
                'callId' => 'call_abc123',
                'sessionToken' => 'sess_xyz789',
            ]);

        $result = $this->service->initiateCall($campaign, $destination, $webhookUrl);

        $this->assertTrue($result['success']);
        $this->assertEquals('call_abc123', $result['call_id']);
        $this->assertEquals('sess_xyz789', $result['session_token']);
        $this->assertNull($result['error']);
    }

    public function test_initiate_call_with_ai_load_balancer_routing(): void
    {
        $campaign = $this->createCampaign([
            'routing_destination_type' => 'ai_load_balancer',
            'routing_destination_id' => 3,
        ]);
        $destination = $this->createDestination();
        $webhookUrl = 'https://example.com/webhooks/cloudonix';

        $this->mockClient
            ->shouldReceive('initiateCall')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::any(),
                Mockery::on(function ($options) {
                    return $options['application'] === 'ai_lb:3';
                })
            )
            ->andReturn([
                'callId' => 'call_alb123',
                'sessionToken' => 'sess_alb789',
            ]);

        $result = $this->service->initiateCall($campaign, $destination, $webhookUrl);

        $this->assertTrue($result['success']);
        $this->assertEquals('call_alb123', $result['call_id']);
    }

    public function test_initiate_call_with_amd_enabled(): void
    {
        $campaign = $this->createCampaign([
            'amd_enabled' => true,
            'amd_mode' => AmdMode::DETECT_MESSAGE_END,
            'amd_timeout' => 45,
            'amd_speech_threshold' => 2000,
            'amd_speech_end_threshold' => 3000,
            'amd_silence_timeout' => 4000,
        ]);
        $destination = $this->createDestination();
        $webhookUrl = 'https://example.com/webhooks/cloudonix';

        $this->mockClient
            ->shouldReceive('initiateCall')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::any(),
                Mockery::on(function ($options) {
                    return $options['machineDetection'] === 'DetectMessageEnd'
                        && $options['machineDetectionTimeout'] === 45
                        && $options['machineDetectionSpeechThreshold'] === 2000
                        && $options['machineDetectionSpeechEndThreshold'] === 3000
                        && $options['machineDetectionSilenceTimeout'] === 4000;
                })
            )
            ->andReturn([
                'callId' => 'call_amd123',
                'sessionToken' => 'sess_amd789',
            ]);

        $result = $this->service->initiateCall($campaign, $destination, $webhookUrl);

        $this->assertTrue($result['success']);
    }

    public function test_initiate_call_handles_api_failure(): void
    {
        $campaign = $this->createCampaign();
        $destination = $this->createDestination();
        $webhookUrl = 'https://example.com/webhooks/cloudonix';

        $this->mockClient
            ->shouldReceive('initiateCall')
            ->once()
            ->andReturn(null); // Simulate API failure

        $result = $this->service->initiateCall($campaign, $destination, $webhookUrl);

        $this->assertFalse($result['success']);
        $this->assertNull($result['call_id']);
        $this->assertNotNull($result['error']);
    }

    public function test_initiate_call_handles_exception(): void
    {
        $campaign = $this->createCampaign();
        $destination = $this->createDestination();
        $webhookUrl = 'https://example.com/webhooks/cloudonix';

        $this->mockClient
            ->shouldReceive('initiateCall')
            ->once()
            ->andThrow(new \Exception('Network timeout'));

        $result = $this->service->initiateCall($campaign, $destination, $webhookUrl);

        $this->assertFalse($result['success']);
        $this->assertEquals('Network timeout', $result['error']);
    }

    public function test_initiate_call_with_hangup_routing_type(): void
    {
        $campaign = $this->createCampaign([
            'routing_destination_type' => 'hangup',
        ]);
        $destination = $this->createDestination();
        $webhookUrl = 'https://example.com/webhooks/cloudonix';

        $this->mockClient
            ->shouldReceive('initiateCall')
            ->once()
            ->with(
                Mockery::any(),
                Mockery::any(),
                Mockery::any(),
                Mockery::on(function ($options) {
                    return $options['hangup'] === true;
                })
            )
            ->andReturn([
                'callId' => 'call_hangup123',
            ]);

        $result = $this->service->initiateCall($campaign, $destination, $webhookUrl);

        $this->assertTrue($result['success']);
    }

    public function test_amd_mode_mapping(): void
    {
        $testCases = [
            [null, 'Enabled'],
            [AmdMode::ENABLED, 'Enabled'],
            [AmdMode::DETECT_MESSAGE_END, 'DetectMessageEnd'],
        ];

        foreach ($testCases as [$input, $expected]) {
            $campaign = $this->createCampaign([
                'amd_enabled' => true,
                'amd_mode' => $input,
            ]);
            $destination = $this->createDestination();
            $webhookUrl = 'https://example.com/webhooks/cloudonix';

            $capturedOptions = null;
            $this->mockClient
                ->shouldReceive('initiateCall')
                ->once()
                ->with(
                    Mockery::any(),
                    Mockery::any(),
                    Mockery::any(),
                    Mockery::on(function ($options) use (&$capturedOptions) {
                        $capturedOptions = $options;

                        return true;
                    })
                )
                ->andReturn(['callId' => 'test']);

            $this->service->initiateCall($campaign, $destination, $webhookUrl);

            $this->assertEquals($expected, $capturedOptions['machineDetection']);
        }
    }

    public function test_get_call_status_delegates_to_client(): void
    {
        $expectedStatus = ['status' => 'connected', 'duration' => 45];

        $this->mockClient
            ->shouldReceive('getCallStatus')
            ->once()
            ->with('call_abc123')
            ->andReturn($expectedStatus);

        $result = $this->service->getCallStatus('call_abc123');

        $this->assertEquals($expectedStatus, $result);
    }

    public function test_hangup_call_delegates_to_client(): void
    {
        $this->mockClient
            ->shouldReceive('hangupCall')
            ->once()
            ->with('call_abc123')
            ->andReturn(true);

        $result = $this->service->hangupCall('call_abc123');

        $this->assertTrue($result);
    }

    public function test_get_call_cdr_delegates_to_client(): void
    {
        $expectedCdr = ['duration' => 120, 'billsec' => 115];

        $this->mockClient
            ->shouldReceive('getCallCdr')
            ->once()
            ->with('call_abc123')
            ->andReturn($expectedCdr);

        $result = $this->service->getCallCdr('call_abc123');

        $this->assertEquals($expectedCdr, $result);
    }
}
