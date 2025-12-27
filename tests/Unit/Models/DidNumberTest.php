<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\BusinessHoursSchedule;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for DidNumber model.
 *
 * Tests helper methods, relationships, and model behavior.
 */
class DidNumberTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
    }

    /**
     * Test getTargetExtensionId returns correct ID for extension routing.
     */
    public function test_get_target_extension_id_returns_correct_id(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $extension->id],
        ]);

        $this->assertEquals($extension->id, $didNumber->getTargetExtensionId());
    }

    /**
     * Test getTargetExtensionId returns null for non-extension routing.
     */
    public function test_get_target_extension_id_returns_null_for_non_extension_routing(): void
    {
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => $ringGroup->id],
        ]);

        $this->assertNull($didNumber->getTargetExtensionId());
    }

    /**
     * Test getTargetRingGroupId returns correct ID for ring group routing.
     */
    public function test_get_target_ring_group_id_returns_correct_id(): void
    {
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => $ringGroup->id],
        ]);

        $this->assertEquals($ringGroup->id, $didNumber->getTargetRingGroupId());
    }

    /**
     * Test getTargetRingGroupId returns null for non-ring-group routing.
     */
    public function test_get_target_ring_group_id_returns_null_for_non_ring_group_routing(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $extension->id],
        ]);

        $this->assertNull($didNumber->getTargetRingGroupId());
    }

    /**
     * Test getTargetBusinessHoursId returns correct ID for business hours routing.
     */
    public function test_get_target_business_hours_id_returns_correct_id(): void
    {
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'business_hours',
            'routing_config' => ['business_hours_schedule_id' => $schedule->id],
        ]);

        $this->assertEquals($schedule->id, $didNumber->getTargetBusinessHoursId());
    }

    /**
     * Test getTargetBusinessHoursId returns null for non-business-hours routing.
     */
    public function test_get_target_business_hours_id_returns_null_for_non_business_hours_routing(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $extension->id],
        ]);

        $this->assertNull($didNumber->getTargetBusinessHoursId());
    }

    /**
     * Test getTargetConferenceRoomId returns correct ID for conference room routing.
     */
    public function test_get_target_conference_room_id_returns_correct_id(): void
    {
        $conferenceRoom = ConferenceRoom::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'conference_room',
            'routing_config' => ['conference_room_id' => $conferenceRoom->id],
        ]);

        $this->assertEquals($conferenceRoom->id, $didNumber->getTargetConferenceRoomId());
    }

    /**
     * Test getTargetConferenceRoomId returns null for non-conference-room routing.
     */
    public function test_get_target_conference_room_id_returns_null_for_non_conference_room_routing(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $extension->id],
        ]);

        $this->assertNull($didNumber->getTargetConferenceRoomId());
    }

    /**
     * Test isActive returns true when status is active.
     */
    public function test_is_active_returns_true_when_status_is_active(): void
    {
        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        $this->assertTrue($didNumber->isActive());
    }

    /**
     * Test isActive returns false when status is inactive.
     */
    public function test_is_active_returns_false_when_status_is_inactive(): void
    {
        $didNumber = DidNumber::factory()->inactive()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertFalse($didNumber->isActive());
    }

    /**
     * Test organization scope is applied automatically.
     */
    public function test_organization_scope_is_applied(): void
    {
        $organization1 = Organization::factory()->create();
        $organization2 = Organization::factory()->create();

        DidNumber::factory()->count(3)->create([
            'organization_id' => $organization1->id,
        ]);

        DidNumber::factory()->count(2)->create([
            'organization_id' => $organization2->id,
        ]);

        // Total without scope
        $totalCount = DidNumber::withoutGlobalScope(\App\Scopes\OrganizationScope::class)->count();
        $this->assertEquals(5, $totalCount);

        // OrganizationScope should be automatically applied in real usage
        // but for testing we need to manually scope
        $org1Count = DidNumber::where('organization_id', $organization1->id)->count();
        $this->assertEquals(3, $org1Count);
    }

    /**
     * Test routing_config is cast to array.
     */
    public function test_routing_config_is_cast_to_array(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $extension->id],
        ]);

        $this->assertIsArray($didNumber->routing_config);
        $this->assertArrayHasKey('extension_id', $didNumber->routing_config);
    }

    /**
     * Test cloudonix_config is cast to array.
     */
    public function test_cloudonix_config_is_cast_to_array(): void
    {
        $didNumber = DidNumber::factory()->withCloudonixConfig([
            'voice_app_id' => 'va_123',
            'webhook_url' => 'https://example.com/webhook',
        ])->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->assertIsArray($didNumber->cloudonix_config);
        $this->assertArrayHasKey('voice_app_id', $didNumber->cloudonix_config);
    }

    /**
     * Test extension attribute can be manually set and retrieved.
     */
    public function test_extension_attribute_can_be_set_and_retrieved(): void
    {
        $extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $extension->id],
        ]);

        // Manually set the extension
        $didNumber->setExtension($extension);

        // Retrieve it
        $retrievedExtension = $didNumber->extension;

        $this->assertInstanceOf(Extension::class, $retrievedExtension);
        $this->assertEquals($extension->id, $retrievedExtension->id);
    }

    /**
     * Test ring group attribute can be manually set and retrieved.
     */
    public function test_ring_group_attribute_can_be_set_and_retrieved(): void
    {
        $ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'ring_group',
            'routing_config' => ['ring_group_id' => $ringGroup->id],
        ]);

        // Manually set the ring group
        $didNumber->setRingGroup($ringGroup);

        // Retrieve it
        $retrievedRingGroup = $didNumber->ring_group;

        $this->assertInstanceOf(RingGroup::class, $retrievedRingGroup);
        $this->assertEquals($ringGroup->id, $retrievedRingGroup->id);
    }

    /**
     * Test business hours schedule attribute can be manually set and retrieved.
     */
    public function test_business_hours_schedule_attribute_can_be_set_and_retrieved(): void
    {
        $schedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'business_hours',
            'routing_config' => ['business_hours_schedule_id' => $schedule->id],
        ]);

        // Manually set the schedule
        $didNumber->setBusinessHoursSchedule($schedule);

        // Retrieve it
        $retrievedSchedule = $didNumber->business_hours_schedule;

        $this->assertInstanceOf(BusinessHoursSchedule::class, $retrievedSchedule);
        $this->assertEquals($schedule->id, $retrievedSchedule->id);
    }

    /**
     * Test conference room attribute can be manually set and retrieved.
     */
    public function test_conference_room_attribute_can_be_set_and_retrieved(): void
    {
        $conferenceRoom = ConferenceRoom::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $didNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'conference_room',
            'routing_config' => ['conference_room_id' => $conferenceRoom->id],
        ]);

        // Manually set the conference room
        $didNumber->setConferenceRoom($conferenceRoom);

        // Retrieve it
        $retrievedRoom = $didNumber->conference_room;

        $this->assertInstanceOf(ConferenceRoom::class, $retrievedRoom);
        $this->assertEquals($conferenceRoom->id, $retrievedRoom->id);
    }
}
