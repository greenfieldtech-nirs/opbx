<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\ApiKey;
use App\Models\Organization;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ApiKeyService
    {
        return app(ApiKeyService::class);
    }

    public function test_create_returns_plaintext_key_and_persists_hash(): void
    {
        $org = Organization::factory()->create();

        [$apiKey, $plaintext] = $this->service()->create(
            organizationId: $org->id,
            name: 'CI key',
            permissions: [['resource' => 'business-hours', 'level' => 'read']],
            createdBy: null,
        );

        $this->assertStringStartsWith('opbxk_', $plaintext);
        $this->assertSame(hash('sha256', $plaintext), $apiKey->token);
        $this->assertDatabaseHas('api_key_permissions', [
            'api_key_id' => $apiKey->id,
            'resource' => 'business-hours',
            'level' => 'read',
        ]);
    }

    public function test_resolve_finds_active_key_by_plaintext(): void
    {
        $org = Organization::factory()->create();
        [$apiKey, $plaintext] = $this->service()->create(
            organizationId: $org->id,
            name: 'k',
            permissions: [],
            createdBy: null,
        );

        $resolved = $this->service()->resolve($plaintext);
        $this->assertNotNull($resolved);
        $this->assertSame($apiKey->id, $resolved->id);
    }

    public function test_resolve_returns_null_for_revoked_key(): void
    {
        $org = Organization::factory()->create();
        [$apiKey, $plaintext] = $this->service()->create(
            organizationId: $org->id,
            name: 'k',
            permissions: [],
            createdBy: null,
        );
        $apiKey->update(['revoked_at' => now()]);

        $this->assertNull($this->service()->resolve($plaintext));
    }

    public function test_resolve_returns_null_for_unknown_token(): void
    {
        $this->assertNull($this->service()->resolve('opbxk_'.str_repeat('x', 40)));
        $this->assertNull($this->service()->resolve('not-a-key'));
    }
}
