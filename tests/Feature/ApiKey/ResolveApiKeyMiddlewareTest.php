<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKey;

use App\Models\Organization;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveApiKeyMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_key_authenticates_request_and_sets_last_used(): void
    {
        $org = Organization::factory()->create();
        [$apiKey, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id,
            name: 'k',
            permissions: [['resource' => 'business-hours', 'level' => 'read']],
            createdBy: null,
        );

        $response = $this->withToken($plaintext)->getJson('/api/v1/business-hours');

        $response->assertStatus(200);
        $this->assertNotNull($apiKey->fresh()->last_used_at);
    }

    public function test_revoked_key_is_unauthenticated(): void
    {
        $org = Organization::factory()->create();
        [$apiKey, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id, name: 'k', permissions: [], createdBy: null,
        );
        $apiKey->update(['revoked_at' => now()]);

        $this->withToken($plaintext)->getJson('/api/v1/business-hours')->assertStatus(401);
    }
}
