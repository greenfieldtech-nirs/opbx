<?php

declare(strict_types=1);

namespace Tests\Feature\Voice;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use App\Services\VoiceRouting\VoiceRoutingManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceErrorCxmlTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'voice-error.cloudonix.net';

    private const KEY = 'voice-error-requests-api-key-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        CloudonixSettings::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'domain_name' => self::DOMAIN,
            'domain_requests_api_key' => self::KEY,
        ]);
    }

    public function test_a_routing_exception_returns_200_cxml_with_say_and_hangup(): void
    {
        $this->mock(VoiceRoutingManager::class, function ($mock): void {
            $mock->shouldReceive('handleInbound')->andThrow(new \RuntimeException('strategy exploded'));
        });

        $response = $this->postJson('/api/voice/route', [
            'CallSid' => 'err-call-1', 'From' => '60000', 'To' => '2001', 'Domain' => self::DOMAIN, 'Direction' => 'subscriber',
        ], ['Authorization' => 'Bearer '.self::KEY]);

        $response->assertOk();
        $this->assertStringContainsString('application/xml', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<Say', $response->getContent());
        $this->assertStringContainsString('<Hangup', $response->getContent());
    }

    public function test_auth_failures_keep_their_status(): void
    {
        $this->postJson('/api/voice/route', [
            'CallSid' => 'err-call-2', 'From' => '60000', 'To' => '2001', 'Domain' => self::DOMAIN, 'Direction' => 'subscriber',
        ], ['Authorization' => 'Bearer wrong'])->assertStatus(401);
    }
}
