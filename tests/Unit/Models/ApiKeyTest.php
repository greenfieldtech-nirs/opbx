<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\ApiKeyPermissionLevel;
use App\Enums\GrantableResource;
use App\Models\ApiKey;
use App\Models\Organization;
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
}
