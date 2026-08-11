<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Supervisor;

use App\Enums\ExtensionType;
use App\Enums\UserRole;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\User;
use App\Services\Supervisor\SupervisorFilterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SupervisorFilterServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_collects_user_ids_and_extension_numbers(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Extension::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'type' => 'user',
            'extension_number' => '1001',
        ]);
        $supervisor->supervisedUsers()->attach($user->id, ['organization_id' => $org->id]);

        $service = new SupervisorFilterService;
        $identifiers = $service->resourceIdentifiers($supervisor);

        $this->assertContains((string) $user->id, $identifiers);
        $this->assertContains('1001', $identifiers);
    }

    public function test_collects_ring_group_ids_and_extension_numbers(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $ringGroup = RingGroup::factory()->create(['organization_id' => $org->id]);
        Extension::factory()->create([
            'organization_id' => $org->id,
            'type' => ExtensionType::RING_GROUP,
            'extension_number' => '2001',
            'configuration' => ['ring_group_id' => $ringGroup->id],
        ]);
        $supervisor->supervisedRingGroups()->attach($ringGroup->id, ['organization_id' => $org->id]);

        $service = new SupervisorFilterService;
        $identifiers = $service->resourceIdentifiers($supervisor);

        $this->assertContains((string) $ringGroup->id, $identifiers);
        $this->assertContains('2001', $identifiers);
    }

    public function test_identifiers_include_plus_prefixed_variants(): void
    {
        $org = Organization::factory()->create();
        $supervisor = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::SUPERVISOR]);
        $user = User::factory()->create(['organization_id' => $org->id, 'role' => UserRole::PBX_USER]);
        Extension::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'type' => 'user',
            'extension_number' => '1001',
        ]);
        $supervisor->supervisedUsers()->attach($user->id, ['organization_id' => $org->id]);

        $service = new SupervisorFilterService;
        $identifiers = $service->resourceIdentifiers($supervisor);

        // session_updates.caller_id/destination are stored E.164-normalized
        // (leading "+") even for internal extension numbers; both forms must
        // be present or supervisor scoping silently excludes live calls.
        $this->assertContains('1001', $identifiers);
        $this->assertContains('+1001', $identifiers);
        $this->assertContains((string) $user->id, $identifiers);
        $this->assertContains('+'.$user->id, $identifiers);
    }

    public function test_returns_empty_for_non_supervisor(): void
    {
        $user = User::factory()->make(['role' => UserRole::PBX_USER]);
        $service = new SupervisorFilterService;
        $this->assertSame([], $service->resourceIdentifiers($user));
    }
}
