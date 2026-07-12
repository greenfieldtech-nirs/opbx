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

    public function test_plaintext_is_never_persisted(): void
    {
        $org = Organization::factory()->create();

        [, $plaintext] = $this->service()->create(
            organizationId: $org->id,
            name: 'k',
            permissions: [],
            createdBy: null,
        );

        $this->assertDatabaseMissing('api_keys', ['token' => $plaintext]);
    }

    public function test_create_is_atomic_when_a_permission_is_invalid(): void
    {
        $org = Organization::factory()->create();

        // Two permissions with the SAME resource violate the
        // unique(['api_key_id','resource']) constraint on the second insert,
        // which must roll back the whole create() — no key, no permissions.
        try {
            $this->service()->create(
                organizationId: $org->id,
                name: 'k',
                permissions: [
                    ['resource' => 'business-hours', 'level' => 'read'],
                    ['resource' => 'business-hours', 'level' => 'write'],
                ],
                createdBy: null,
            );
            $this->fail('Expected the duplicate-resource insert to throw.');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertDatabaseCount('api_keys', 0);
        $this->assertDatabaseCount('api_key_permissions', 0);
    }
}
