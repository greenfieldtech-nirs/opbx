<?php

declare(strict_types=1);

namespace Tests\Feature\ApiKey;

use App\Models\Organization;
use App\Services\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnforceApiKeyScopeTest extends TestCase
{
    use RefreshDatabase;

    private function keyFor(array $permissions): string
    {
        $org = Organization::factory()->create();
        [, $plaintext] = app(ApiKeyService::class)->create(
            organizationId: $org->id, name: 'k', permissions: $permissions, createdBy: null,
        );

        return $plaintext;
    }

    public function test_read_grant_allows_get_but_blocks_write(): void
    {
        $token = $this->keyFor([['resource' => 'business-hours', 'level' => 'read']]);

        $this->withToken($token)->getJson('/api/v1/business-hours')->assertStatus(200);
        $this->withToken($token)->postJson('/api/v1/business-hours', [])->assertStatus(403);
    }

    public function test_write_grant_allows_write_verbs(): void
    {
        $token = $this->keyFor([['resource' => 'business-hours', 'level' => 'write']]);

        // 422 (validation) is acceptable here — it proves the request passed the
        // scope gate and reached the controller. A 403 would mean the gate blocked it.
        $status = $this->withToken($token)->postJson('/api/v1/business-hours', [])->status();
        $this->assertNotSame(403, $status);
    }

    public function test_ungranted_resource_is_forbidden(): void
    {
        $token = $this->keyFor([['resource' => 'business-hours', 'level' => 'read']]);

        $this->withToken($token)->getJson('/api/v1/recordings')->assertStatus(403);
    }
}
