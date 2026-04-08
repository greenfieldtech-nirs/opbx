<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AutoDialer;

use App\Enums\AmdMode;
use App\Enums\RoutingDestinationType;
use App\Models\AutoDialerCampaign;
use App\Models\AutoDialerDestination;
use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Services\AutoDialer\AutoDialerCloudonixService;
use App\Services\PhoneNumberService;
use App\Services\VoiceRouting\OutboundRoutingService;
use Mockery;
use Tests\TestCase;

/**
 * Auto Dialer Cloudonix Service Tests
 *
 * Tests the Cloudonix integration layer for the auto dialer.
 * Verifies organization-specific credentials and outbound whitelist routing.
 */
class AutoDialerCloudonixServiceTest extends TestCase
{
    private AutoDialerCloudonixService $service;

    private $mockOutboundRouting;

    private $mockPhoneNumberService;

    private Organization $organization;

    private CloudonixSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockOutboundRouting = Mockery::mock(OutboundRoutingService::class);
        $this->mockPhoneNumberService = Mockery::mock(PhoneNumberService::class);

        $this->service = new AutoDialerCloudonixService(
            $this->mockOutboundRouting,
            $this->mockPhoneNumberService
        );

        $this->organization = Organization::factory()->make(['id' => 1]);

        // Create Cloudonix settings for the organization
        $this->settings = new CloudonixSettings([
            'organization_id' => $this->organization->id,
            'domain_uuid' => 'test-domain-uuid-123',
            'domain_api_key' => 'XIBB0E3CD4FB1F46698DE5FC51B49A012E',
            'domain_name' => 'test.cloudonix.io',
        ]);
        $this->settings->id = 1;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function createCampaign(array $overrides = []): AutoDialerCampaign
    {
        $defaults = [
            'id' => 1,
            'organization_id' => $this->organization->id,
            'name' => 'Test Campaign',
            'caller_id' => '+15551234567',
            'routing_destination_type' => RoutingDestinationType::AI_ASSISTANT,
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

    public function test_initiate_call_requires_cloudonix_configuration(): void
    {
        $campaign = $this->createCampaign();
        $destination = $this->createDestination();
        $webhookUrl = 'https://example.com/webhooks/cloudonix';

        // Create unconfigured settings
        $unconfiguredSettings = new CloudonixSettings([
            'organization_id' => $this->organization->id,
            'domain_uuid' => null,
            'domain_api_key' => null,
        ]);

        $result = $this->service->initiateCall($campaign, $destination, $unconfiguredSettings, $webhookUrl);

        $this->assertFalse($result['success']);
        $this->assertEquals('Cloudonix not configured for this organization', $result['error']);
    }

    public function test_initiate_call_with_whitelist_trunk(): void
    {
        $campaign = $this->createCampaign();
        $destination = $this->createDestination();
        $webhookUrl = 'https://example.com/webhooks/cloudonix';

        // Mock whitelist entry with trunk
        $whitelistEntry = new OutboundWhitelist([
            'organization_id' => $this->organization->id,
            'name' => 'US Calls',
            'destination_country' => 'US',
            'outbound_trunk_name' => 'twilio-us',
            'status' => 'active',
        ]);
        $whitelistEntry->id = 1;

        $this->mockOutboundRouting
            ->shouldReceive('findOutboundWhitelistEntry')
            ->once()
            ->with($this->organization->id, $destination->phone_number)
            ->andReturn($whitelistEntry);

        // Note: We can't mock the actual API call without mocking the HTTP client,
        // but we verify the service properly looks up the trunk
        $result = $this->service->initiateCall($campaign, $destination, $this->settings, $webhookUrl);

        // Since we're not mocking the HTTP layer, the call will fail,
        // but we can verify the error message doesn't mention missing configuration
        $this->assertNotEquals('Cloudonix not configured for this organization', $result['error']);
    }

    public function test_initiate_call_without_whitelist_trunk(): void
    {
        $campaign = $this->createCampaign();
        $destination = $this->createDestination();
        $webhookUrl = 'https://example.com/webhooks/cloudonix';

        // Mock no whitelist entry found
        $this->mockOutboundRouting
            ->shouldReceive('findOutboundWhitelistEntry')
            ->once()
            ->with($this->organization->id, $destination->phone_number)
            ->andReturn(null);

        $result = $this->service->initiateCall($campaign, $destination, $this->settings, $webhookUrl);

        // Call should proceed without trunk (let Cloudonix determine)
        $this->assertNotEquals('Cloudonix not configured for this organization', $result['error']);
    }

    public function test_initiate_call_whitelist_entry_without_trunk(): void
    {
        $campaign = $this->createCampaign();
        $destination = $this->createDestination();
        $webhookUrl = 'https://example.com/webhooks/cloudonix';

        // Mock whitelist entry without trunk name
        $whitelistEntry = new OutboundWhitelist([
            'organization_id' => $this->organization->id,
            'name' => 'US Calls',
            'destination_country' => 'US',
            'outbound_trunk_name' => null, // No trunk configured
            'status' => 'active',
        ]);
        $whitelistEntry->id = 1;

        $this->mockOutboundRouting
            ->shouldReceive('findOutboundWhitelistEntry')
            ->once()
            ->with($this->organization->id, $destination->phone_number)
            ->andReturn($whitelistEntry);

        $result = $this->service->initiateCall($campaign, $destination, $this->settings, $webhookUrl);

        // Call should proceed without trunk (let Cloudonix determine)
        $this->assertNotEquals('Cloudonix not configured for this organization', $result['error']);
    }

    public function test_build_routing_payload_returns_cxml(): void
    {
        $campaign = $this->createCampaign([
            'routing_destination_type' => RoutingDestinationType::HANGUP,
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('buildRoutingPayload');
        $method->setAccessible(true);

        $options = $method->invoke($this->service, $campaign);

        // Should return cxml key with hangup CXML
        $this->assertArrayHasKey('cxml', $options);
        $this->assertStringContainsString('<Hangup/>', $options['cxml']);
    }

    public function test_generate_cxml_for_campaign_hangup(): void
    {
        $campaign = $this->createCampaign([
            'routing_destination_type' => RoutingDestinationType::HANGUP,
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('generateCxmlForCampaign');
        $method->setAccessible(true);

        $cxml = $method->invoke($this->service, $campaign);

        $this->assertStringContainsString('<Hangup/>', $cxml);
    }

    public function test_amd_mode_mapping_with_enum(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('mapAmdMode');
        $method->setAccessible(true);

        // Test with enum - returns the enum value directly
        $this->assertEquals('Enabled', $method->invoke($this->service, AmdMode::ENABLED));
        $this->assertEquals('DetectMessageEnd', $method->invoke($this->service, AmdMode::DETECT_MESSAGE_END));
    }

    public function test_amd_mode_mapping_with_string(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('mapAmdMode');
        $method->setAccessible(true);

        // Test with string values - maps to Cloudonix format
        $this->assertEquals('Enable', $method->invoke($this->service, 'detect_wait'));
        $this->assertEquals('DetectMessageEnd', $method->invoke($this->service, 'detect_beep'));
        $this->assertEquals('Enable', $method->invoke($this->service, null));
    }

    public function test_validate_credentials_delegates_to_client(): void
    {
        // This is a static method that delegates to CloudonixClient
        // We can't easily mock static calls, but we can verify it doesn't throw
        $result = AutoDialerCloudonixService::validateCredentials(
            'invalid-domain',
            'invalid-key'
        );

        // Should return invalid for bad credentials
        $this->assertFalse($result['valid']);
        $this->assertNull($result['profile']);
    }
}
