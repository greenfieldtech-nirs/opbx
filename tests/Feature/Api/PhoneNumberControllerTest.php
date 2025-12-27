<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\RingGroupStatus;
use App\Enums\RingGroupStrategy;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\BusinessHoursSchedule;
use App\Models\ConferenceRoom;
use App\Models\DidNumber;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\RingGroup;
use App\Models\RingGroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Phone Numbers (DID) API endpoints test suite.
 *
 * Tests CRUD operations, tenant isolation, authorization, and validation
 * for phone number management.
 */
class PhoneNumberControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private Organization $otherOrganization;
    private User $owner;
    private User $admin;
    private User $user;
    private User $otherOrgOwner;
    private Extension $extension;
    private RingGroup $ringGroup;
    private BusinessHoursSchedule $businessHours;
    private ConferenceRoom $conferenceRoom;

    protected function setUp(): void
    {
        parent::setUp();

        // Create organizations
        $this->organization = Organization::factory()->create(['name' => 'Test Org']);
        $this->otherOrganization = Organization::factory()->create(['name' => 'Other Org']);

        // Create users with different roles
        $this->owner = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::OWNER,
        ]);

        $this->admin = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_ADMIN,
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'role' => UserRole::PBX_USER,
        ]);

        $this->otherOrgOwner = User::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'role' => UserRole::OWNER,
        ]);

        // Create routing targets for testing
        $this->extension = Extension::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);

        $this->ringGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => RingGroupStatus::ACTIVE,
        ]);

        // Add at least one member to ring group
        RingGroupMember::factory()->create([
            'ring_group_id' => $this->ringGroup->id,
            'extension_id' => $this->extension->id,
            'priority' => 1,
        ]);

        $this->businessHours = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $this->conferenceRoom = ConferenceRoom::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => UserStatus::ACTIVE,
        ]);
    }

    /**
     * Test that Owner can list phone numbers for their organization.
     */
    public function test_owner_can_list_phone_numbers(): void
    {
        Sanctum::actingAs($this->owner);

        // Create phone numbers for this organization
        DidNumber::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
        ]);

        // Create phone numbers for other organization (should not be returned)
        DidNumber::factory()->count(2)->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $response = $this->getJson('/api/v1/phone-numbers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'organization_id',
                        'phone_number',
                        'friendly_name',
                        'routing_type',
                        'routing_config',
                        'status',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    }

    /**
     * Test that PBX Admin can list phone numbers for their organization.
     */
    public function test_pbx_admin_can_list_phone_numbers(): void
    {
        Sanctum::actingAs($this->admin);

        DidNumber::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->getJson('/api/v1/phone-numbers');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /**
     * Test that PBX User can list phone numbers (read-only).
     */
    public function test_pbx_user_can_list_phone_numbers(): void
    {
        Sanctum::actingAs($this->user);

        DidNumber::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->getJson('/api/v1/phone-numbers');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /**
     * Test that users cannot see phone numbers from different organization.
     */
    public function test_cannot_see_phone_numbers_from_different_organization(): void
    {
        Sanctum::actingAs($this->owner);

        // Create phone numbers for other organization
        DidNumber::factory()->count(3)->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $response = $this->getJson('/api/v1/phone-numbers');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /**
     * Test that Owner can create phone number with extension routing.
     */
    public function test_owner_can_create_phone_number_with_extension_routing(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'phone_number' => '+12125551234',
            'friendly_name' => 'Main Office Line',
            'routing_type' => 'extension',
            'routing_config' => [
                'extension_id' => $this->extension->id,
            ],
            'status' => 'active',
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'phone_number' => '+12125551234',
                'routing_type' => 'extension',
            ]);

        $this->assertDatabaseHas('did_numbers', [
            'organization_id' => $this->organization->id,
            'phone_number' => '+12125551234',
            'routing_type' => 'extension',
        ]);
    }

    /**
     * Test that Owner can create phone number with ring group routing.
     */
    public function test_owner_can_create_phone_number_with_ring_group_routing(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'phone_number' => '+12125551235',
            'friendly_name' => 'Sales Line',
            'routing_type' => 'ring_group',
            'routing_config' => [
                'ring_group_id' => $this->ringGroup->id,
            ],
            'status' => 'active',
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'phone_number' => '+12125551235',
                'routing_type' => 'ring_group',
            ]);
    }

    /**
     * Test that Owner can create phone number with business hours routing.
     */
    public function test_owner_can_create_phone_number_with_business_hours_routing(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'phone_number' => '+12125551236',
            'routing_type' => 'business_hours',
            'routing_config' => [
                'business_hours_schedule_id' => $this->businessHours->id,
            ],
            'status' => 'active',
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'phone_number' => '+12125551236',
                'routing_type' => 'business_hours',
            ]);
    }

    /**
     * Test that Owner can create phone number with conference room routing.
     */
    public function test_owner_can_create_phone_number_with_conference_room_routing(): void
    {
        Sanctum::actingAs($this->owner);

        $data = [
            'phone_number' => '+12125551237',
            'routing_type' => 'conference_room',
            'routing_config' => [
                'conference_room_id' => $this->conferenceRoom->id,
            ],
            'status' => 'active',
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'phone_number' => '+12125551237',
                'routing_type' => 'conference_room',
            ]);
    }

    /**
     * Test that PBX Admin can create phone number.
     */
    public function test_pbx_admin_can_create_phone_number(): void
    {
        Sanctum::actingAs($this->admin);

        $data = [
            'phone_number' => '+12125551238',
            'routing_type' => 'extension',
            'routing_config' => [
                'extension_id' => $this->extension->id,
            ],
            'status' => 'active',
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(201);
    }

    /**
     * Test that PBX User cannot create phone number.
     */
    public function test_pbx_user_cannot_create_phone_number(): void
    {
        Sanctum::actingAs($this->user);

        $data = [
            'phone_number' => '+12125551239',
            'routing_type' => 'extension',
            'routing_config' => [
                'extension_id' => $this->extension->id,
            ],
            'status' => 'active',
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(403);
    }

    /**
     * Test validation of phone number format (E.164).
     */
    public function test_validates_phone_number_format(): void
    {
        Sanctum::actingAs($this->owner);

        // Invalid phone number (no country code)
        $data = [
            'phone_number' => '2125551234',
            'routing_type' => 'extension',
            'routing_config' => [
                'extension_id' => $this->extension->id,
            ],
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    /**
     * Test validation of phone number uniqueness (across all organizations).
     */
    public function test_validates_phone_number_uniqueness(): void
    {
        Sanctum::actingAs($this->owner);

        // Create a phone number
        DidNumber::factory()->create([
            'organization_id' => $this->otherOrganization->id,
            'phone_number' => '+12125551240',
        ]);

        // Try to create duplicate phone number in different organization
        $data = [
            'phone_number' => '+12125551240',
            'routing_type' => 'extension',
            'routing_config' => [
                'extension_id' => $this->extension->id,
            ],
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    /**
     * Test validation that target extension exists and is active.
     */
    public function test_validates_target_extension_exists_and_is_active(): void
    {
        Sanctum::actingAs($this->owner);

        // Create inactive extension
        $inactiveExtension = Extension::factory()->inactive()->create([
            'organization_id' => $this->organization->id,
        ]);

        $data = [
            'phone_number' => '+12125551241',
            'routing_type' => 'extension',
            'routing_config' => [
                'extension_id' => $inactiveExtension->id,
            ],
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['routing_config.extension_id']);
    }

    /**
     * Test validation that target ring group exists, is active, and has members.
     */
    public function test_validates_target_ring_group_exists_active_and_has_members(): void
    {
        Sanctum::actingAs($this->owner);

        // Create ring group with no members
        $emptyRingGroup = RingGroup::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => RingGroupStatus::ACTIVE,
        ]);

        $data = [
            'phone_number' => '+12125551242',
            'routing_type' => 'ring_group',
            'routing_config' => [
                'ring_group_id' => $emptyRingGroup->id,
            ],
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['routing_config.ring_group_id']);
    }

    /**
     * Test validation that target business hours schedule exists and is active.
     */
    public function test_validates_target_business_hours_schedule_exists_and_is_active(): void
    {
        Sanctum::actingAs($this->owner);

        // Create inactive business hours schedule
        $inactiveSchedule = BusinessHoursSchedule::factory()->create([
            'organization_id' => $this->organization->id,
            'status' => \App\Enums\BusinessHoursStatus::INACTIVE,
        ]);

        $data = [
            'phone_number' => '+12125551243',
            'routing_type' => 'business_hours',
            'routing_config' => [
                'business_hours_schedule_id' => $inactiveSchedule->id,
            ],
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['routing_config.business_hours_schedule_id']);
    }

    /**
     * Test validation that target conference room exists and is active.
     */
    public function test_validates_target_conference_room_exists_and_is_active(): void
    {
        Sanctum::actingAs($this->owner);

        // Create inactive conference room
        $inactiveRoom = ConferenceRoom::factory()->inactive()->create([
            'organization_id' => $this->organization->id,
        ]);

        $data = [
            'phone_number' => '+12125551244',
            'routing_type' => 'conference_room',
            'routing_config' => [
                'conference_room_id' => $inactiveRoom->id,
            ],
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['routing_config.conference_room_id']);
    }

    /**
     * Test that cannot route to resource from different organization.
     */
    public function test_cannot_route_to_resource_from_different_organization(): void
    {
        Sanctum::actingAs($this->owner);

        // Create extension in different organization
        $otherExtension = Extension::factory()->create([
            'organization_id' => $this->otherOrganization->id,
        ]);

        $data = [
            'phone_number' => '+12125551245',
            'routing_type' => 'extension',
            'routing_config' => [
                'extension_id' => $otherExtension->id,
            ],
        ];

        $response = $this->postJson('/api/v1/phone-numbers', $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['routing_config.extension_id']);
    }

    /**
     * Test that Owner can update phone number routing.
     */
    public function test_owner_can_update_phone_number_routing(): void
    {
        Sanctum::actingAs($this->owner);

        $phoneNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $this->extension->id],
        ]);

        $data = [
            'routing_type' => 'ring_group',
            'routing_config' => [
                'ring_group_id' => $this->ringGroup->id,
            ],
        ];

        $response = $this->putJson('/api/v1/phone-numbers/' . $phoneNumber->id, $data);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'routing_type' => 'ring_group',
            ]);

        $this->assertDatabaseHas('did_numbers', [
            'id' => $phoneNumber->id,
            'routing_type' => 'ring_group',
        ]);
    }

    /**
     * Test that cannot update phone_number field (immutable).
     */
    public function test_cannot_update_phone_number_field(): void
    {
        Sanctum::actingAs($this->owner);

        $phoneNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+12125551246',
        ]);

        $data = [
            'phone_number' => '+12125559999',
        ];

        $response = $this->putJson('/api/v1/phone-numbers/' . $phoneNumber->id, $data);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);

        // Verify phone number hasn't changed
        $this->assertDatabaseHas('did_numbers', [
            'id' => $phoneNumber->id,
            'phone_number' => '+12125551246',
        ]);
    }

    /**
     * Test that Owner can delete phone number.
     */
    public function test_owner_can_delete_phone_number(): void
    {
        Sanctum::actingAs($this->owner);

        $phoneNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->deleteJson('/api/v1/phone-numbers/' . $phoneNumber->id);

        $response->assertStatus(204);

        $this->assertDatabaseMissing('did_numbers', [
            'id' => $phoneNumber->id,
        ]);
    }

    /**
     * Test that PBX User cannot delete phone number.
     */
    public function test_pbx_user_cannot_delete_phone_number(): void
    {
        Sanctum::actingAs($this->user);

        $phoneNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->deleteJson('/api/v1/phone-numbers/' . $phoneNumber->id);

        $response->assertStatus(403);

        $this->assertDatabaseHas('did_numbers', [
            'id' => $phoneNumber->id,
        ]);
    }

    /**
     * Test filtering by status.
     */
    public function test_can_filter_by_status(): void
    {
        Sanctum::actingAs($this->owner);

        DidNumber::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
            'status' => 'active',
        ]);

        DidNumber::factory()->count(3)->create([
            'organization_id' => $this->organization->id,
            'status' => 'inactive',
        ]);

        $response = $this->getJson('/api/v1/phone-numbers?status=active');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /**
     * Test filtering by routing_type.
     */
    public function test_can_filter_by_routing_type(): void
    {
        Sanctum::actingAs($this->owner);

        DidNumber::factory()->count(2)->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'extension',
        ]);

        DidNumber::factory()->count(3)->routeToRingGroup($this->ringGroup)->create([
            'organization_id' => $this->organization->id,
        ]);

        $response = $this->getJson('/api/v1/phone-numbers?routing_type=extension');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /**
     * Test searching by phone_number or friendly_name.
     */
    public function test_can_search_by_phone_number_or_friendly_name(): void
    {
        Sanctum::actingAs($this->owner);

        DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+12125551111',
            'friendly_name' => 'Test Line',
        ]);

        DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'phone_number' => '+12125552222',
            'friendly_name' => 'Other Line',
        ]);

        // Search by phone number
        $response = $this->getJson('/api/v1/phone-numbers?search=1111');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Search by friendly name
        $response = $this->getJson('/api/v1/phone-numbers?search=Test');
        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /**
     * Test eager loading of related resources based on routing_type.
     */
    public function test_eager_loads_correct_related_resource_based_on_routing_type(): void
    {
        Sanctum::actingAs($this->owner);

        $phoneNumber = DidNumber::factory()->create([
            'organization_id' => $this->organization->id,
            'routing_type' => 'extension',
            'routing_config' => ['extension_id' => $this->extension->id],
        ]);

        $response = $this->getJson('/api/v1/phone-numbers/' . $phoneNumber->id);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'phone_number',
                    'routing_type',
                    'extension' => [
                        'id',
                        'extension_number',
                    ],
                ],
            ])
            ->assertJsonMissing(['ring_group', 'business_hours_schedule', 'conference_room']);
    }
}
