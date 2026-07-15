<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ensures the users index/show endpoints serialize the assigned extension so
 * the frontend Users table can render the Extension column.
 */
class UsersIndexExtensionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);
    }

    /** @test */
    public function users_index_includes_assigned_extension_number(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'extension_number' => '4242',
        ]);

        $this->actingAs($this->owner);

        $response = $this->getJson('/api/v1/users');
        $response->assertOk();

        $target = collect($response->json('data'))
            ->firstWhere('id', $user->id);

        $this->assertNotNull($target);
        $this->assertNotNull($target['extension'] ?? null, 'extension should be serialized');
        $this->assertSame('4242', $target['extension']['extension_number']);
    }

    /** @test */
    public function users_index_returns_null_extension_when_unassigned(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        $this->actingAs($this->owner);

        $target = collect($this->getJson('/api/v1/users')->json('data'))
            ->firstWhere('id', $user->id);

        $this->assertNotNull($target);
        $this->assertNull($target['extension'] ?? null);
    }

    /** @test */
    public function user_show_includes_assigned_extension_number(): void
    {
        $user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'user_id' => $user->id,
            'extension_number' => '5150',
        ]);

        $this->actingAs($this->owner);

        $response = $this->getJson("/api/v1/users/{$user->id}");
        $response->assertOk();

        $this->assertSame('5150', $response->json('data.extension.extension_number'));
    }
}
