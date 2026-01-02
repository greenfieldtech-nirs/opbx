<?php

namespace Tests\Feature;

use App\Enums\ExtensionType;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use App\Services\Security\RoutingSentryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class VoiceRoutingRefactorTest extends TestCase
{
    use DatabaseTransactions;

    protected Organization $organization;
    protected DidNumber $did;
    protected User $user;
    protected Extension $extension;
    protected CloudonixSettings $settings;
    protected string $apiKey = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        // Create Organization
        $this->organization = Organization::factory()->create(['status' => 'active']);

        // Create User
        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        // Create Extension
        $this->extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $this->user->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => 'active',
        ]);

        // Create a second extension for internal call testing
        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1002',
            'type' => ExtensionType::USER,
            'status' => 'active',
        ]);

        // Create DID linked to Extension
        $this->did = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+1234567890',
            'status' => 'active',
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $this->extension->id],
        ]);

        // Create Cloudonix Settings for Auth
        $this->settings = CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'domain_requests_api_key' => $this->apiKey,
        ]);
    }

    private function getAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/xml',
        ];
    }

    /** @test */
    public function it_routes_inbound_call_to_user_extension()
    {
        // Mock Sentry to allow
        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1987654321',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/xml; charset=utf-8');

        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString($this->extension->extension_number, $content);
    }

    /** @test */
    public function it_blocks_call_if_sentry_denies()
    {
        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn([
                'allowed' => false,
                'reason' => 'Blacklisted',
                'action' => 'reject'
            ]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1666666666',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('<Hangup', $content);
    }

    /** @test */
    public function it_routes_to_ring_group()
    {
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Sales',
            'status' => 'active',
            'strategy' => 'simultaneous',
            'timeout' => 20,
        ]);

        $ringGroup->members()->create([
            'extension_id' => $this->extension->id,
            'priority' => 1,
        ]);

        $this->did->update([
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => $ringGroup->id],
        ]);

        $this->mock(RoutingSentryService::class, function ($mock) {
            $mock->shouldReceive('checkInbound')->andReturn(['allowed' => true]);
        });

        $response = $this->postJson(route('voice.route'), [
            'From' => '+1555555555',
            'To' => $this->did->phone_number,
            'Direction' => 'inbound',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('<Dial timeout="20">', $content);
        $this->assertStringContainsString($this->extension->extension_number, $content);
    }

    /** @test */
    public function it_routes_internal_extension_call()
    {
        $this->mock(RoutingSentryService::class, function ($mock) {
            // Sentry check is skipped for internal calls in current Manager implementation
            $mock->shouldReceive('checkInbound')->never();
        });

        $response = $this->postJson(route('voice.route'), [
            'To' => $this->extension->extension_number,
            'From' => '1002',
            'Direction' => 'internal',
            'CallSid' => 'CA' . md5(uniqid()),
        ], $this->getAuthHeaders());

        $response->assertStatus(200);
        $content = $response->getContent();
        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString($this->extension->extension_number, $content);
    }
}
