<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VoiceRouting;

use App\Models\DidNumber;
use App\Models\Organization;
use App\Services\InboundBlacklist\InboundBlacklistService;
use App\Services\VoiceRouting\BusinessHoursRoutingService;
use App\Services\VoiceRouting\ExtensionRoutingService;
use App\Services\VoiceRouting\InboundRoutingService;
use App\Services\VoiceRouting\IvrRoutingService;
use App\Services\VoiceRouting\OutboundRoutingService;
use App\Services\VoiceRouting\RingGroupRoutingService;
use App\Services\VoiceRouting\VoiceRoutingCacheService;
use App\Services\VoiceRouting\VoiceRoutingManager;
use App\Services\VoiceRouting\VoiceRoutingStrategyExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Unit tests for VoiceRoutingManager direction-based routing.
 */
class VoiceRoutingManagerDirectionTest extends TestCase
{
    use RefreshDatabase;

    private VoiceRoutingManager $routingManager;

    private VoiceRoutingCacheService $cacheService;

    private OutboundRoutingService $outboundRouting;

    private BusinessHoursRoutingService $businessHoursRouting;

    private InboundBlacklistService $inboundBlacklistService;

    private InboundRoutingService $inboundRouting;

    private ExtensionRoutingService $extensionRouting;

    private IvrRoutingService $ivrRouting;

    private RingGroupRoutingService $ringGroupRouting;

    private VoiceRoutingStrategyExecutor $strategyExecutor;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test organization
        $this->organization = Organization::factory()->create(['status' => 'active']);

        // Mock Log to prevent actual logging during tests
        Log::shouldReceive('info')->andReturn(null);
        Log::shouldReceive('warning')->andReturn(null);
        Log::shouldReceive('error')->andReturn(null);
        Log::shouldReceive('debug')->andReturn(null);

        // Mock dependencies
        $this->cacheService = $this->mock(VoiceRoutingCacheService::class);
        $this->outboundRouting = $this->mock(OutboundRoutingService::class);
        $this->businessHoursRouting = $this->mock(BusinessHoursRoutingService::class);
        $this->inboundBlacklistService = $this->mock(InboundBlacklistService::class);

        // Create real supporting services
        $this->strategyExecutor = new VoiceRoutingStrategyExecutor([]);
        $this->extensionRouting = new ExtensionRoutingService($this->cacheService);
        $this->inboundRouting = new InboundRoutingService(
            $this->cacheService,
            $this->extensionRouting,
            $this->strategyExecutor
        );
        $this->ivrRouting = $this->mock(IvrRoutingService::class);
        $this->ringGroupRouting = $this->mock(RingGroupRoutingService::class);

        // Create VoiceRoutingManager with mocked dependencies
        $this->routingManager = new VoiceRoutingManager(
            $this->cacheService,
            $this->inboundBlacklistService,
            $this->outboundRouting,
            $this->businessHoursRouting,
            $this->inboundRouting,
            $this->extensionRouting,
            $this->ivrRouting,
            $this->ringGroupRouting,
            $this->strategyExecutor
        );
    }

    public function test_handle_inbound_dispatches_to_subscriber_direction()
    {
        $request = new Request([
            'Direction' => 'subscriber',
            'To' => '1001',
            'From' => '1002',
            '_organization_id' => $this->organization->id,
        ]);

        // Mock the cache service to return null for extension lookup
        $this->cacheService->shouldReceive('getExtension')
            ->once()
            ->with($this->organization->id, '1001')
            ->andReturn(null);

        $this->cacheService->shouldReceive('getActiveBusinessHoursSchedule')
            ->never(); // Should not be called for subscriber direction

        $response = $this->routingManager->handleInbound($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Extension not found', $response->getContent());
    }

    public function test_handle_inbound_dispatches_to_external_inbound_call_when_from_is_not_did()
    {
        $request = new Request([
            'Direction' => 'inbound',
            'To' => '+15551234567',
            'From' => '+15559876543',
            '_organization_id' => $this->organization->id,
            'CallSid' => 'test-call-sid',
        ]);

        // Mock business hours check to return null (no override)
        $this->cacheService->shouldReceive('getActiveBusinessHoursSchedule')
            ->once()
            ->with($this->organization->id)
            ->andReturn(null);

        $response = $this->routingManager->handleInbound($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('DID destination not configured', $response->getContent());
    }

    public function test_handle_inbound_dispatches_to_internal_call_when_from_is_assigned_did()
    {
        // Create a DID for the From number
        DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+15559876543',
            'status' => 'active',
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => 1],
        ]);

        $request = new Request([
            'Direction' => 'inbound',
            'To' => '1001',
            'From' => '+15559876543', // This is an assigned DID
            '_organization_id' => $this->organization->id,
            'CallSid' => 'test-call-sid',
        ]);

        // Mock extension lookup to return null (no extension found for destination)
        $this->cacheService->shouldReceive('getExtension')
            ->once()
            ->with($this->organization->id, '1001')
            ->andReturn(null);

        // Business hours should NOT be checked for internal calls
        $this->cacheService->shouldReceive('getActiveBusinessHoursSchedule')
            ->never();

        $response = $this->routingManager->handleInbound($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Extension not found', $response->getContent());
    }

    public function test_handle_inbound_dispatches_to_outbound_direction()
    {
        $request = new Request([
            'Direction' => 'outbound',
            'To' => '+15551234567',
            'From' => '1001',
            '_organization_id' => $this->organization->id,
        ]);

        // Mock the cache service to return null for extension lookup (caller not found)
        $this->cacheService->shouldReceive('getExtension')
            ->once()
            ->with($this->organization->id, '1001')
            ->andReturn(null);

        $response = $this->routingManager->handleInbound($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Outbound call not permitted', $response->getContent());
    }

    public function test_handle_inbound_dispatches_to_application_direction()
    {
        $request = new Request([
            'Direction' => 'application',
            'To' => '+15551234567',
            'From' => '+15559876543',
            '_organization_id' => $this->organization->id,
        ]);

        $response = $this->routingManager->handleInbound($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Extension not found', $response->getContent());
    }

    public function test_handle_inbound_handles_unknown_direction()
    {
        $request = new Request([
            'Direction' => 'unknown',
            'To' => '+15551234567',
            'From' => '+15559876543',
            '_organization_id' => $this->organization->id,
            'CallSid' => 'test-call-sid',
        ]);

        // Should fall back to inbound behavior and check business hours
        $this->cacheService->shouldReceive('getActiveBusinessHoursSchedule')
            ->once()
            ->with($this->organization->id)
            ->andReturn(null);

        $response = $this->routingManager->handleInbound($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('DID destination not configured', $response->getContent());
    }

    public function test_handle_inbound_handles_missing_direction()
    {
        $request = new Request([
            'To' => '+15551234567',
            'From' => '+15559876543',
            '_organization_id' => $this->organization->id,
            'CallSid' => 'test-call-sid',
        ]);

        // Should fall back to inbound behavior and check business hours
        $this->cacheService->shouldReceive('getActiveBusinessHoursSchedule')
            ->once()
            ->with($this->organization->id)
            ->andReturn(null);

        $response = $this->routingManager->handleInbound($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('DID destination not configured', $response->getContent());
    }

    public function test_handle_inbound_direction_validates_required_parameters()
    {
        $request = new Request([
            'Direction' => 'inbound',
            'To' => '', // Empty To
            'From' => '+15559876543',
            '_organization_id' => $this->organization->id,
        ]);

        $response = $this->routingManager->handleInbound($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Missing call parameters', $response->getContent());
    }

    public function test_handle_inbound_direction_validates_required_from_parameter()
    {
        $request = new Request([
            'Direction' => 'inbound',
            'To' => '+15551234567',
            'From' => '', // Empty From
            '_organization_id' => $this->organization->id,
        ]);

        $response = $this->routingManager->handleInbound($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('Missing call parameters', $response->getContent());
    }
}
