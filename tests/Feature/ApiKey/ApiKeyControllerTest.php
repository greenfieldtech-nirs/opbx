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
        $key = ApiKey::factory()->for($owner->organization)->create();

        $response = $this->actingAs($owner)->getJson('/api/v1/api-keys');
        $response->assertStatus(200);

        $json = json_encode($response->json('data'));
        // Neither the stored hash, the 'token' field, nor any plaintext prefix.
        $this->assertStringNotContainsString('token', strtolower($json));
        $this->assertStringNotContainsString($key->token, $json);
        $this->assertStringNotContainsString('opbxk_', $json);
    }

    public function test_show_never_returns_secret(): void
    {
        $owner = $this->owner();
        $key = ApiKey::factory()->for($owner->organization)->create();

        $response = $this->actingAs($owner)->getJson("/api/v1/api-keys/{$key->id}");
        $response->assertStatus(200);

        $json = json_encode($response->json('data'));
        $this->assertStringNotContainsString('token', strtolower($json));
        $this->assertStringNotContainsString($key->token, $json);
    }

    public function test_non_owner_cannot_create_key(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org)->create(['role' => UserRole::PBX_ADMIN->value]);

        // Create is the privilege-granting operation; a non-owner must be denied.
        $this->actingAs($admin)->postJson('/api/v1/api-keys', [
            'name' => 'nope',
            'permissions' => [['resource' => 'business-hours', 'level' => 'read']],
        ])->assertStatus(403);

        $this->assertDatabaseCount('api_keys', 0);
    }

    public function test_update_rejects_unknown_resource(): void
    {
        $owner = $this->owner();
        $key = ApiKey::factory()->for($owner->organization)->create();

        // A key cannot be edited to grant a resource outside the allowlist.
        $this->actingAs($owner)->putJson("/api/v1/api-keys/{$key->id}", [
            'permissions' => [['resource' => 'settings', 'level' => 'write']],
        ])->assertStatus(422);
    }

    public function test_update_rejects_blank_name(): void
    {
        $owner = $this->owner();
        $key = ApiKey::factory()->for($owner->organization)->create(['name' => 'Original']);

        // An explicit empty name must 422, not silently blank the key's name.
        $this->actingAs($owner)->putJson("/api/v1/api-keys/{$key->id}", [
            'name' => '',
        ])->assertStatus(422);

        $this->assertSame('Original', $key->fresh()->name);
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
