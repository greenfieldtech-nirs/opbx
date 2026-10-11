<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\DidNumber;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ResourceInUseDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_an_in_use_ring_group_returns_409_with_references(): void
    {
        $org = Organization::factory()->create();
        Sanctum::actingAs(User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::OWNER]));
        $group = RingGroup::factory()->create(['organization_id' => $org->id]);
        DidNumber::factory()->routeToRingGroup($group)->create(['organization_id' => $org->id]);

        $this->deleteJson("/api/v1/ring-groups/{$group->id}")
            ->assertStatus(409)
            ->assertJsonStructure(['error', 'message', 'references']);

        $this->assertDatabaseHas('ring_groups', ['id' => $group->id]);
    }
}
