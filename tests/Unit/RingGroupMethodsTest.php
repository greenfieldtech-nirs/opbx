<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UserStatus;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RingGroup model methods test suite.
 *
 * Tests the getMembers() and getActiveMemberCount() methods for various scenarios.
 */
class RingGroupMethodsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private RingGroup $ringGroup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        $this->ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
        ]);
    }

    public function test_get_members_returns_active_extensions_ordered_by_priority(): void
    {
        // Create 3 active extensions with different priorities
        $ext1 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
            'extension_number' => '1001',
        ]);
        $ext2 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
            'extension_number' => '1002',
        ]);
        $ext3 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
            'extension_number' => '1003',
        ]);

        // Add members with specific priorities (not in order)
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $ext2->id,
            'priority' => 2,
        ]);
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $ext1->id,
            'priority' => 1,
        ]);
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $ext3->id,
            'priority' => 3,
        ]);

        $members = $this->ringGroup->getMembers();

        $this->assertCount(3, $members);
        // Should be ordered by priority: ext1 (1), ext2 (2), ext3 (3)
        $this->assertEquals('1001', $members[0]->extension_number);
        $this->assertEquals('1002', $members[1]->extension_number);
        $this->assertEquals('1003', $members[2]->extension_number);
    }

    public function test_get_members_filters_out_inactive_extensions(): void
    {
        // Create 2 active and 1 inactive extension
        $activeExt1 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);
        $activeExt2 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);
        $inactiveExt = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::INACTIVE,
        ]);

        // Add all 3 as members
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $activeExt1->id,
            'priority' => 1,
        ]);
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $inactiveExt->id,
            'priority' => 2,
        ]);
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $activeExt2->id,
            'priority' => 3,
        ]);

        $members = $this->ringGroup->getMembers();

        // Should only return the 2 active extensions
        $this->assertCount(2, $members);
        $this->assertTrue($members->contains('id', $activeExt1->id));
        $this->assertTrue($members->contains('id', $activeExt2->id));
        $this->assertFalse($members->contains('id', $inactiveExt->id));
    }

    public function test_get_members_returns_empty_collection_when_no_members(): void
    {
        // Ring group with no members
        $members = $this->ringGroup->getMembers();

        $this->assertCount(0, $members);
        $this->assertTrue($members->isEmpty());
    }

    public function test_get_members_returns_empty_collection_when_all_members_inactive(): void
    {
        // Create 2 inactive extensions
        $inactiveExt1 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::INACTIVE,
        ]);
        $inactiveExt2 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::INACTIVE,
        ]);

        // Add them as members
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $inactiveExt1->id,
            'priority' => 1,
        ]);
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $inactiveExt2->id,
            'priority' => 2,
        ]);

        $members = $this->ringGroup->getMembers();

        $this->assertCount(0, $members);
        $this->assertTrue($members->isEmpty());
    }

    public function test_get_active_member_count_returns_correct_count(): void
    {
        // Create 3 active extensions
        $ext1 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);
        $ext2 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);
        $ext3 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        // Add them as members
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $ext1->id,
            'priority' => 1,
        ]);
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $ext2->id,
            'priority' => 2,
        ]);
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $ext3->id,
            'priority' => 3,
        ]);

        $this->assertEquals(3, $this->ringGroup->getActiveMemberCount());
    }

    public function test_get_active_member_count_excludes_inactive_members(): void
    {
        // Create 2 active and 2 inactive extensions
        $activeExt1 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);
        $activeExt2 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);
        $inactiveExt1 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::INACTIVE,
        ]);
        $inactiveExt2 = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::INACTIVE,
        ]);

        // Add all 4 as members
        foreach ([$activeExt1, $inactiveExt1, $activeExt2, $inactiveExt2] as $i => $ext) {
            RingGroupMember::factory()->create([
                'ring_group_id' => $this->ringGroup->id,
                'extension_id' => $ext->id,
                'priority' => $i + 1,
            ]);
        }

        // Should only count the 2 active members
        $this->assertEquals(2, $this->ringGroup->getActiveMemberCount());
    }

    public function test_get_active_member_count_returns_zero_when_no_members(): void
    {
        $this->assertEquals(0, $this->ringGroup->getActiveMemberCount());
    }

    public function test_get_members_eager_loads_extension_data(): void
    {
        // Create active extension
        $ext = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
            'extension_number' => '1001',
        ]);

        // Add as member
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $ext->id,
            'priority' => 1,
        ]);

        $members = $this->ringGroup->getMembers();

        // Verify extension is loaded with expected attributes
        $this->assertCount(1, $members);
        $member = $members->first();
        $this->assertNotNull($member);
        $this->assertEquals('1001', $member->extension_number);
        $this->assertEquals(UserStatus::ACTIVE, $member->status);
    }
}
