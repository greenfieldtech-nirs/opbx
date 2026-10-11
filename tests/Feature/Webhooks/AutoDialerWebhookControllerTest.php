<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\CloudonixSettings;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Auth characterisation for the auto-dialer webhook routes.
 */
class AutoDialerWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const DOMAIN = 'autodialer-hook.cloudonix.net';

    private const KEY = 'autodialer-hook-requests-api-key-123456';

    protected function setUp(): void
    {
        parent::setUp();

        CloudonixSettings::factory()->create([
            'organization_id' => Organization::factory()->create()->id,
            'domain_name' => self::DOMAIN,
            'domain_requests_api_key' => self::KEY,
        ]);
    }

    public function test_call_status_requires_credentials(): void
    {
        $this->postJson('/api/webhooks/auto-dialer/call-status', ['domain' => self::DOMAIN, 'session_token' => 'x'])
            ->assertUnauthorized();
    }

    public function test_amd_result_requires_credentials(): void
    {
        $this->postJson('/api/webhooks/auto-dialer/amd-result', ['domain' => self::DOMAIN, 'session_token' => 'x'])
            ->assertUnauthorized();
    }

    public function test_call_status_rejects_wrong_key(): void
    {
        $this->postJson('/api/webhooks/auto-dialer/call-status', ['domain' => self::DOMAIN, 'session_token' => 'x'], ['Authorization' => 'Bearer wrong'])
            ->assertUnauthorized();
    }

    public function test_authenticated_call_status_for_unknown_session_is_404(): void
    {
        $this->postJson('/api/webhooks/auto-dialer/call-status', ['domain' => self::DOMAIN, 'session_token' => 'nope', 'status' => 'answered'], ['Authorization' => 'Bearer '.self::KEY])
            ->assertNotFound();
    }

    public function test_authenticated_call_status_without_token_is_400(): void
    {
        $this->postJson('/api/webhooks/auto-dialer/call-status', ['domain' => self::DOMAIN], ['Authorization' => 'Bearer '.self::KEY])
            ->assertStatus(400);
    }
}
