<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKey;

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $org = Organization::factory()->create();

        return User::factory()->for($org)->create(['role' => UserRole::OWNER->value]);
    }

    public function test_create_returns_plaintext_key_once(): void
    {
        $owner = $this->owner();

        $response = $this->actingAs($owner)->postJson('/api/v1/api-keys', [
            'name' => 'Zapier',
            'permissions' => [
                ['resource' => 'business-hours', 'level' => 'read'],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['id', 'name', 'permissions'], 'key']);
        $this->assertStringStartsWith('opbxk_', $response->json('key'));
    }

    public function test_list_never_returns_secret(): void
    {
        $owner = $this->owner();
        ApiKey::factory()->for($owner->organization)->create();

        $response = $this->actingAs($owner)->getJson('/api/v1/api-keys');
        $response->assertStatus(200);
        $this->assertStringNotContainsString('token', strtolower(json_encode($response->json('data'))));
    }

    public function test_create_rejects_unknown_resource(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->postJson('/api/v1/api-keys', [
            'name' => 'bad',
            'permissions' => [['resource' => 'settings', 'level' => 'read']],
        ])->assertStatus(422);
    }

    public function test_delete_revokes_key(): void
    {
        $owner = $this->owner();
        $key = ApiKey::factory()->for($owner->organization)->create();

        $this->actingAs($owner)->deleteJson("/api/v1/api-keys/{$key->id}")->assertStatus(200);
        $this->assertNotNull($key->fresh()->revoked_at);
    }

    public function test_grantable_resources_lists_allowlist(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->getJson('/api/v1/api-keys/grantable-resources')
            ->assertStatus(200)
            ->assertJsonFragment(['business-hours']);
    }
}
