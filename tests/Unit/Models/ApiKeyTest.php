<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ApiKeyPermissionLevel;
use App\Enums\GrantableResource;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_an_organization_and_exposes_organization_id(): void
    {
        $org = Organization::factory()->create();
        $key = ApiKey::factory()->for($org)->create();

        $this->assertSame($org->id, $key->organization_id);
        $this->assertSame($org->id, $key->organization->id);
    }

    public function test_permission_for_returns_level_for_granted_resource(): void
    {
        $key = ApiKey::factory()->create();
        $key->permissions()->create([
            'resource' => GrantableResource::BUSINESS_HOURS->value,
            'level' => ApiKeyPermissionLevel::READ->value,
        ]);

        $this->assertSame(
            ApiKeyPermissionLevel::READ,
            $key->levelForResource(GrantableResource::BUSINESS_HOURS)
        );
        $this->assertNull($key->levelForResource(GrantableResource::RECORDINGS));
    }

    public function test_get_auth_identifier_returns_key_id(): void
    {
        $key = ApiKey::factory()->create();
        $this->assertSame($key->id, $key->getAuthIdentifier());
    }

    public function test_is_revoked_reflects_revoked_at(): void
    {
        $active = ApiKey::factory()->create();
        $revoked = ApiKey::factory()->revoked()->create();

        $this->assertFalse($active->isRevoked());
        $this->assertTrue($revoked->isRevoked());
    }

    public function test_token_is_hidden_from_array(): void
    {
        $key = ApiKey::factory()->create();

        $this->assertArrayNotHasKey('token', $key->toArray());
    }

    public function test_auth_contract_names(): void
    {
        $key = ApiKey::factory()->create();

        $this->assertSame('id', $key->getAuthIdentifierName());
        $this->assertSame('token', $key->getAuthPasswordName());
    }

    public function test_get_auth_password_throws_to_prevent_credential_auth(): void
    {
        $key = ApiKey::factory()->create();

        $this->expectException(\LogicException::class);
        $key->getAuthPassword();
    }

    public function test_relations_resolve_without_active_tenant_scope(): void
    {
        // The key must resolve its organization/creator before a tenant context
        // exists (it authenticates the request). Verify the scope-bypass relations
        // work with no authenticated user / no active org scope.
        $org = Organization::factory()->create();
        $creator = User::factory()->for($org)->create();
        $key = ApiKey::factory()->for($org)->create(['created_by' => $creator->id]);

        // Re-fetch without any auth context to prove the relations bypass OrganizationScope.
        $fresh = ApiKey::withoutGlobalScopes()->find($key->id);

        $this->assertNotNull($fresh->organization);
        $this->assertSame($org->id, $fresh->organization->id);
        $this->assertNotNull($fresh->createdBy);
        $this->assertSame($creator->id, $fresh->createdBy->id);
    }
}
