<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKey;

use App\Models\Extension;
use App\Models\Organization;
use App\Services\ApiKeyService;
use App\Services\Email\Contracts\TransactionalEmailInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * API-key principals (App\Models\ApiKey, not User) must not crash code paths
 * that consume the authenticated principal. Regression coverage for the
 * IvrAudioConfig TypeError and sibling sinks.
 */
class ApiKeyPrincipalCompatTest extends TestCase
{
    use RefreshDatabase;

    private function keyFor(Organization $org, array $permissions): string
    {
        [, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id, name: 'k', permissions: $permissions, createdBy: null,
        );

        return $plaintext;
    }

    public function test_ivr_menu_create_with_api_key(): void
    {
        $org = Organization::factory()->create();
        $extension = Extension::factory()->create(['organization_id' => $org->id, 'type' => 'user']);
        $token = $this->keyFor($org, [['resource' => 'ivr-menus', 'level' => 'write']]);

        $response = $this->withToken($token)->postJson('/api/v1/ivr-menus', [
            'name' => 'Key-created IVR',
            'max_timeout' => 5,
            'inter_digit_timeout' => 3,
            'max_turns' => 2,
            'failover_destination_type' => 'hangup',
            'status' => 'active',
            'options' => [
                [
                    'input_digits' => '1',
                    'destination_type' => 'extension',
                    'destination_id' => $extension->id,
                    'priority' => 1,
                ],
            ],
        ]);

        // 201 created — and definitely not a 500 TypeError.
        $response->assertStatus(201);
        $this->assertDatabaseHas('ivr_menus', ['name' => 'Key-created IVR']);
    }

    public function test_user_invite_with_api_key(): void
    {
        // Invitations flow through Auth0 — enable it for this test.
        Config::set('services.auth0.enabled', true);
        Config::set('services.auth0.domain', 'tenant.us.auth0.com');
        Config::set('services.auth0.client_id', 'id');
        Config::set('services.auth0.client_secret', 'secret');
        Config::set('services.auth0.redirect_uri', 'https://app.opbx.com/ui/auth/callback');

        $org = Organization::factory()->create();
        $token = $this->keyFor($org, [['resource' => 'users', 'level' => 'write']]);

        $emailService = $this->mock(TransactionalEmailInterface::class);
        $emailService->shouldReceive('sendAsync')->andReturnTrue();

        $response = $this->withToken($token)->postJson('/api/v1/users/invite', [
            'email' => 'invited-by-key@example.com',
        ]);

        // Invitation accepted — not a 500 TypeError.
        $this->assertNotSame(500, $response->status());
        $this->assertContains($response->status(), [200, 201, 202]);
    }

    public function test_recording_create_with_api_key_is_a_clean_403(): void
    {
        $org = Organization::factory()->create();
        $token = $this->keyFor($org, [['resource' => 'recordings', 'level' => 'write']]);

        $response = $this->withToken($token)->postJson('/api/v1/recordings', [
            'name' => 'Key recording',
            'type' => 'remote',
            'remote_url' => 'https://example.com/prompt.wav',
        ]);

        // Recordings are attributed to a user (created_by FK) — keys get a clear
        // 403, never a 500 from a FK violation.
        $response->assertStatus(403);
        $this->assertStringContainsString('user token', $response->json('message'));
    }
}
