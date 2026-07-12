<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKey;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_list_keys(): void
    {
        $org = Organization::factory()->create();
        $owner = User::factory()->for($org)->create(['role' => UserRole::OWNER->value]);

        $this->actingAs($owner)->getJson('/api/v1/api-keys')->assertStatus(200);
    }

    public function test_non_owner_cannot_list_keys(): void
    {
        $org = Organization::factory()->create();
        $admin = User::factory()->for($org)->create(['role' => UserRole::PBX_ADMIN->value]);

        $this->actingAs($admin)->getJson('/api/v1/api-keys')->assertStatus(403);
    }

    public function test_api_key_cannot_manage_keys(): void
    {
        $org = Organization::factory()->create();
        [, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id,
            name: 'k',
            permissions: [['resource' => 'users', 'level' => 'write']],
            createdBy: null,
        );

        // Even a broadly-scoped key must never reach key management.
        $this->withToken($plaintext)->getJson('/api/v1/api-keys')->assertStatus(403);
    }
}
