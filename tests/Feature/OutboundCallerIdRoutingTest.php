<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ExtensionType;
use App\Enums\UserStatus;
use App\Models\CloudonixSettings;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\OutboundWhitelist;
use App\Scopes\OrganizationScope;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Integration test for the outbound (subscriber-direction) caller-ID precedence:
 * extension's selected DID -> whitelist rule's selected DID -> "00000000".
 */
class OutboundCallerIdRoutingTest extends TestCase
{
    use DatabaseTransactions;

    private Organization $organization;

    private VoiceRoutingManager $routingManager;

    private Extension $extension;

    private OutboundWhitelist $whitelist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['status' => 'active']);

        CloudonixSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'webhook_base_url' => 'https://test.example.com',
        ]);

        $this->routingManager = app(VoiceRoutingManager::class);

        $this->extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'extension_number' => '1001',
            'type' => ExtensionType::USER,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->whitelist = OutboundWhitelist::factory()->create([
            'organization_id' => $this->organization->id,
            'destination_country' => 'US',
            'destination_prefix' => '+1',
            'outbound_trunk_name' => 'trunk-us',
            'status' => 'active',
        ]);
    }

    private function did(string $number): DidNumber
    {
        return DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => $number,
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => 1],
            'status' => 'active',
        ]);
    }

    private function outboundRequest(string $to, string $from): Request
    {
        $request = new Request;
        $request->merge([
            'To' => $to,
            'From' => $from,
            'CallSid' => 'test-call-'.$to,
            'Direction' => 'subscriber',
            '_organization_id' => $this->organization->id,
        ]);

        return $request;
    }

    private function handle(Request $request): string
    {
        $response = OrganizationScope::bypass(fn () => $this->routingManager->handleInbound($request));
        $this->assertEquals(200, $response->getStatusCode());

        return (string) $response->getContent();
    }

    public function test_outbound_uses_extension_caller_id_over_whitelist(): void
    {
        $extDid = $this->did('+18005550001');
        $wlDid = $this->did('+18005550002');
        $this->extension->update(['default_caller_id_did_id' => $extDid->id]);
        $this->whitelist->update(['default_caller_id_did_id' => $wlDid->id]);

        $content = $this->handle($this->outboundRequest('+15551234567', '1001'));

        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('callerId="+18005550001"', $content);
        $this->assertStringContainsString('trunks="trunk-us"', $content);
        $this->assertStringNotContainsString('callerName=', $content);
    }

    public function test_outbound_falls_back_to_whitelist_caller_id(): void
    {
        $wlDid = $this->did('+18005550002');
        $this->whitelist->update(['default_caller_id_did_id' => $wlDid->id]);

        $content = $this->handle($this->outboundRequest('+15551234567', '1001'));

        $this->assertStringContainsString('callerId="+18005550002"', $content);
        $this->assertStringContainsString('trunks="trunk-us"', $content);
        $this->assertStringNotContainsString('callerName=', $content);
    }

    public function test_outbound_presents_zeros_when_no_caller_id_configured(): void
    {
        $content = $this->handle($this->outboundRequest('+15551234567', '1001'));

        $this->assertStringContainsString('<Dial', $content);
        $this->assertStringContainsString('callerId="00000000"', $content);
        $this->assertStringContainsString('trunks="trunk-us"', $content);
        $this->assertStringNotContainsString('callerName=', $content);
    }
}
